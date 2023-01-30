<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\imagetransforms;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\EagerImageTransformerInterface;
use craft\base\imagetransforms\ImageEditorTransformerInterface;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\base\LocalFsInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\errors\FsException;
use craft\errors\ImageTransformException;
use craft\events\ImageTransformerOperationEvent;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use craft\helpers\ImageTransforms as TransformHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\image\Raster;
use craft\models\ImageTransform;
use craft\models\ImageTransformIndex;
use craft\queue\jobs\GenerateImageTransform;
use DateTime;
use Exception;
use Imagine\Image\Format;
use Throwable;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * ImageTransformer transforms image assets using GD or ImageMagick.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 *
 * @property-read int $editedImageHeight
 * @property-read int $editedImageWidth
 * @property-read array $pendingTransformIndexIds
 */
class ImageTransformer extends Component implements ImageTransformerInterface, EagerImageTransformerInterface, ImageEditorTransformerInterface
{
    /**
     * @event ImageTransformerOperationEvent The event that is fired when an image is transformed
     */
    public const EVENT_TRANSFORM_IMAGE = 'transformImage';

    /**
     * @event ImageTransformerOperationEvent The event that is fired when a generated image transform is deleted
     */
    public const EVENT_DELETE_TRANSFORMED_IMAGE = 'deleteTransformedImage';

    /**
     * @var ImageTransformIndex[]
     */
    protected array $eagerLoadedTransformIndexes = [];

    /**
     * @var array
     */
    protected array $imageEditorData = [];

    /**
     * @inheritdoc
     */
    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $fs = $asset->getVolume()->getTransformFs();

        if (!$fs->hasUrls) {
            throw new NotSupportedException('The asset’s volume’s transform filesystem doesn’t have URLs.');
        }

        $index = $this->getTransformIndex($asset, $imageTransform);
        $uri = str_replace('\\', '/', $this->getTransformBasePath($asset)) . $this->getTransformUri($asset, $index);

        // If it's a local filesystem, double-check that the transform exists
        if ($fs instanceof LocalFsInterface && $index->fileExists && !$fs->fileExists($uri)) {
            $index->fileExists = false;
            $this->storeTransformIndexData($index);
        }

        if (!$index->fileExists) {
            if (!$immediately) {
                // Add a Generate Image Transform job to the queue, in case the temp URL never gets requested
                Queue::push(new GenerateImageTransform([
                    'transformId' => $index->id,
                ]));

                // Return the temporary transform URL
                return UrlHelper::actionUrl('assets/generate-transform', [
                    'transformId' => $index->id,
                ], showScriptName: false);
            }

            // Is the transform being generated by another request?
            if ($index->inProgress) {
                for ($try = 1; $try <= 30; $try++) {
                    if ($index->error) {
                        throw new ImageTransformException(Craft::t('app', 'Failed to generate transform with id of {id}.', [
                            'id' => $index->id,
                        ]));
                    }

                    // Wait a second and check again
                    App::maxPowerCaptain();
                    sleep(1);
                    $index = $this->getTransformIndexModelById($index->id);
                    if (!$index->inProgress) {
                        break;
                    }
                }
            }

            // No file, then
            if (!$index->fileExists) {
                // Mark the transform as in progress
                $index->inProgress = true;
                $this->storeTransformIndexData($index);

                // Generate the transform
                try {
                    $this->generateTransform($index);
                } catch (Exception $e) {
                    $index->inProgress = false;
                    $index->fileExists = false;
                    $index->error = true;
                    $this->storeTransformIndexData($index);

                    throw new ImageTransformException(Craft::t('app', 'Failed to generate transform with id of {id}.', [
                        'id' => $index->id,
                    ]), previous: $e);
                }

                $index->inProgress = false;
                $index->fileExists = true;
                $this->storeTransformIndexData($index);
            }
        }

        $url = sprintf('%s/%s', sprintf(StringHelper::ensureRight($fs->getRootUrl() ?? '', '/')), $uri);
        return UrlHelper::urlWithParams($url, AssetsHelper::revParams($asset, $index->dateUpdated));
    }

    /**
     * @inheritdoc
     */
    public function invalidateAssetTransforms(Asset $asset): void
    {
        $transformIndexes = $this->getAllCreatedTransformsForAsset($asset);

        foreach ($transformIndexes as $transformIndex) {
            $this->deleteImageTransformFile($asset, $transformIndex);
        }

        $this->deleteTransformIndexDataByAssetId($asset->id);
    }

    /**
     * @param Asset $asset
     * @param ImageTransformIndex $transformIndex
     * @throws InvalidConfigException
     */
    public function deleteImageTransformFile(Asset $asset, ImageTransformIndex $transformIndex): void
    {
        $path = $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $transformIndex);

        if ($this->hasEventHandlers(static::EVENT_DELETE_TRANSFORMED_IMAGE)) {
            $this->trigger(static::EVENT_DELETE_TRANSFORMED_IMAGE, new ImageTransformerOperationEvent([
                'asset' => $asset,
                'imageTransformIndex' => $transformIndex,
                'path' => $path,
            ]));
        }

        try {
            $asset->getVolume()->getTransformFs()->deleteFile($path);
        } catch (InvalidConfigException) {
            // nbd
        }
    }

    /**
     * @inheritdoc
     */
    public function eagerLoadTransforms(array $transforms, array $assets): void
    {
        // Index the assets by ID
        $assetsById = ArrayHelper::index($assets, 'id');
        $indexCondition = ['or'];
        $transformsByFingerprint = [];

        foreach ($transforms as $transform) {
            $transformString = $fingerprint = TransformHelper::getTransformString($transform);

            if ($transform->format !== null) {
                $fingerprint .= ':' . $transform->format;
            }

            $transformsByFingerprint[$fingerprint] = $transform;
            $transformCondition = ['and', ['transformString' => $transformString]];

            if ($transform->format === null) {
                $transformCondition[] = ['format' => null];
            } else {
                $transformCondition[] = ['format' => $transform->format];
                $fingerprint .= ':' . $transform->format;
            }

            $indexCondition[] = $transformCondition;
            $transformsByFingerprint[$fingerprint] = $transform;
        }

        // Query for the indexes
        $results = $this->_createTransformIndexQuery()
            ->where([
                'and',
                ['assetId' => array_keys($assetsById)],
                $indexCondition,
            ])
            ->all();

        // Index the valid transform indexes by fingerprint, and capture the IDs of indexes that should be deleted
        $invalidIndexIds = [];

        foreach ($results as $result) {
            // Get the transform's fingerprint
            $transformFingerprint = $result['transformString'];

            if ($result['format']) {
                $transformFingerprint .= ':' . $result['format'];
            }

            // Is it still valid?
            $transform = $transformsByFingerprint[$transformFingerprint];
            $asset = $assetsById[$result['assetId']];

            if ($this->validateTransformIndexResult($result, $transform, $asset)) {
                $indexFingerprint = $result['assetId'] . ':' . $transformFingerprint;
                $this->eagerLoadedTransformIndexes[$indexFingerprint] = $result;
            } else {
                $invalidIndexIds[] = $result['id'];
            }
        }

        // Delete any invalid indexes
        if (!empty($invalidIndexIds)) {
            Db::delete(Table::IMAGETRANSFORMINDEX, [
                'id' => $invalidIndexIds,
            ], [], Craft::$app->getImageTransforms()->db);
        }
    }

    // Protected methods
    // =============================================================

    /**
     * Return a subfolder used by the Transform Index for the Asset.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $transformIndex
     * @return string
     * @throws InvalidConfigException
     */
    protected function getTransformSubfolder(Asset $asset, ImageTransformIndex $transformIndex): string
    {
        $path = $transformIndex->transformString;

        if (!empty($transformIndex->filename) && $transformIndex->filename !== $asset->getFilename()) {
            $path .= DIRECTORY_SEPARATOR . $asset->id;
        }

        return $path;
    }

    /**
     * Return the filename used by the Transform Index for the Asset.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $transformIndex
     * @return string
     * @throws InvalidConfigException
     */
    protected function getTransformFilename(Asset $asset, ImageTransformIndex $transformIndex): string
    {
        return $transformIndex->filename ?: $asset->getFilename();
    }

    /**
     * Returns the path to a transform, relative to the asset's folder.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $transformIndex
     * @return string
     * @throws InvalidConfigException
     */
    protected function getTransformSubpath(Asset $asset, ImageTransformIndex $transformIndex): string
    {
        return $this->getTransformSubfolder($asset, $transformIndex) . DIRECTORY_SEPARATOR . $this->getTransformFilename($asset, $transformIndex);
    }

    /**
     * Returns the URI for a transform, relative to the asset's folder.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $index
     * @return string
     */
    protected function getTransformUri(Asset $asset, ImageTransformIndex $index): string
    {
        $uri = $this->getTransformSubpath($asset, $index);
        return str_replace('\\', '/', $uri);
    }

    /**
     * Generate the actual image for the Asset by the transform index.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $index
     * @throws ImageTransformException If a transform index has an invalid transform assigned.
     */
    protected function generateTransformedImage(Asset $asset, ImageTransformIndex $index): void
    {
        if (!Image::canManipulateAsImage($asset->getExtension())) {
            return;
        }

        $volume = $asset->getVolume();
        $transformFs = $volume->getTransformFs();
        $transformPath = $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $index);

        if ($transformFs->fileExists($transformPath)) {
            $dateModified = $transformFs->getDateModified($transformPath);
            $parameterChangeTime = $index->getTransform()->parameterChangeTime;

            if (!$parameterChangeTime || $parameterChangeTime->getTimestamp() <= $dateModified) {
                // The file already exists and isn't stale yet
                return;
            }

            try {
                $volume->getFs()->deleteFile($transformPath);
            } catch (Throwable) {
                // Unlikely, but if it got deleted while we were comparing timestamps, don't freak out.
            }
        }

        $tempPath = TransformHelper::generateTransform($asset, $index->getTransform(), function() use ($index) {
            $this->storeTransformIndexData($index);
        }, $image);

        if ($this->hasEventHandlers(static::EVENT_TRANSFORM_IMAGE)) {
            $event = new ImageTransformerOperationEvent([
                'asset' => $asset,
                'imageTransformIndex' => $index,
                'path' => $transformPath,
                'image' => $image,
                'tempPath' => $tempPath,
            ]);
            $this->trigger(static::EVENT_TRANSFORM_IMAGE, $event);
            $tempPath = $event->tempPath;
        }

        $stream = fopen($tempPath, 'rb');

        try {
            $transformFs->writeFileFromStream($transformPath, $stream);
        } catch (FsException $e) {
            Craft::$app->getErrorHandler()->logException($e);
        }

        fclose($stream);
        FileHelper::unlink($tempPath);
    }

    /**
     * Check if a transformed image exists. If it does not, attempt to generate it.
     *
     * @param ImageTransformIndex $index
     * @return bool true if transform exists for the index
     * @throws ImageTransformException
     * @deprecated in 4.4.0. [[generateTransform()]] should be used instead.
     */
    protected function procureTransformedImage(ImageTransformIndex $index): bool
    {
        $this->generateTransform($index);
        return true;
    }

    /**
     * @throws ImageTransformException
     */
    private function generateTransform(ImageTransformIndex $index): void
    {
        $asset = Craft::$app->getAssets()->getAssetById($index->assetId);

        if (!$asset) {
            throw new ImageTransformException('Asset not found - ' . $index->assetId);
        }

        $volume = $asset->getVolume();

        $index->detectedFormat = $index->format ?: TransformHelper::detectTransformFormat($asset);
        $transformFilename = pathinfo($asset->getFilename(), PATHINFO_FILENAME) . '.' . $index->detectedFormat;
        $index->filename = $transformFilename;

        $matchFound = $this->getSimilarTransformIndex($asset, $index);
        $fs = $volume->getTransformFs();

        $target = $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $index);
        // If we have a match, copy the file.
        if ($matchFound) {
            $from = $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $matchFound);

            // Sanity check
            try {
                if ($fs->fileExists($target)) {
                    return;
                }

                $fs->copyFile($from, $target);
            } catch (FsException $exception) {
                throw new ImageTransformException('There was a problem re-using an existing transform.', 0, $exception);
            }
        } else {
            $this->generateTransformedImage($asset, $index);
        }

        if (!$fs->fileExists($target)) {
            throw new ImageTransformException('There was a problem generating the image transform.');
        }
    }

    /**
     * Get a transform URL by the transform index model.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $index
     * @return string
     * @throws ImageTransformException If there was an error generating the transform.
     * @deprecated in 4.4.0. [[getTransformUrl()]] should be used instead.
     */
    protected function ensureTransformUrlByIndexModel(Asset $asset, ImageTransformIndex $index): string
    {
        return $this->getTransformUrl($asset, $index->getTransform(), true);
    }

    /**
     * Get a transform index row. If it doesn't exist - create one.
     *
     * @param Asset $asset
     * @param ImageTransform|string|array|null $transform
     * @return ImageTransformIndex
     * @throws ImageTransformException if the transform cannot be found by the handle
     */
    public function getTransformIndex(Asset $asset, mixed $transform): ImageTransformIndex
    {
        $transform = TransformHelper::normalizeTransform($transform);

        if ($transform === null) {
            throw new ImageTransformException('There was a problem finding the transform.');
        }

        $transformString = TransformHelper::getTransformString($transform);

        // Was it eager-loaded?
        $fingerprint = $asset->id . ':' . $transformString . ($transform->format === null ? '' : ':' . $transform->format);

        if (isset($this->eagerLoadedTransformIndexes[$fingerprint])) {
            $result = $this->eagerLoadedTransformIndexes[$fingerprint];
            return new ImageTransformIndex((array)$result);
        }

        // Check if an entry exists already
        $query = $this->_createTransformIndexQuery()
            ->where([
                'assetId' => $asset->id,
                'transformString' => $transformString,
            ]);

        if ($transform->format === null) {
            // A generated auto-transform will have its format set to null, but the filename will be populated.
            $query->andWhere(['format' => null]);
        } else {
            $query->andWhere(['format' => $transform->format]);
        }

        $result = $query->one();

        if ($result) {
            $existingIndex = new ImageTransformIndex($result);

            if ($this->validateTransformIndexResult($result, $transform, $asset)) {
                return $existingIndex;
            }

            // Delete the out-of-date record
            Db::delete(Table::IMAGETRANSFORMINDEX, [
                'id' => $result['id'],
            ], [], Craft::$app->getImageTransforms()->db);

            // And the generated transform itself, too
            $this->deleteImageTransformFile($asset, $existingIndex);
        }

        // Create a new record
        $index = new ImageTransformIndex([
            'assetId' => $asset->id,
            'format' => $transform->format,
            'transformer' => $transform->getTransformer(),
            'dateIndexed' => Db::prepareDateForDb(new DateTime()),
            'transformString' => $transformString,
            'fileExists' => false,
            'inProgress' => false,
            'transform' => $transform,
        ]);

        $this->storeTransformIndexData($index);
        return $index;
    }

    /**
     * Validates a transform index result to see if the index is still valid for a given asset.
     *
     * @param array $result
     * @param ImageTransform $transform
     * @param array|Asset $asset The asset object or a raw database result
     * @return bool Whether the index result is still valid
     */
    protected function validateTransformIndexResult(array $result, ImageTransform $transform, array|Asset $asset): bool
    {
        // If the transform hasn't been generated yet, it's probably not yet invalid.
        if (empty($result['dateIndexed'])) {
            return true;
        }

        // If the asset has been modified since the time the index was created, it’s no longer valid
        $dateModified = ArrayHelper::getValue($asset, 'dateModified');
        if ($result['dateIndexed'] < Db::prepareDateForDb($dateModified)) {
            return false;
        }

        // If it’s not a named transform, consider it valid
        if (!$transform->getIsNamedTransform()) {
            return true;
        }

        // If the named transform's dimensions have changed since the time the index was created, it's no longer valid
        if ($result['dateIndexed'] < Db::prepareDateForDb($transform->parameterChangeTime)) {
            return false;
        }

        return true;
    }

    /**
     * Store a transform index data by it's model.
     *
     * @param ImageTransformIndex $index
     * @return ImageTransformIndex
     */
    public function storeTransformIndexData(ImageTransformIndex $index): ImageTransformIndex
    {
        $values = Db::prepareValuesForDb(
            $index->toArray([
                'assetId',
                'transformer',
                'filename',
                'format',
                'transformString',
                'volumeId',
                'fileExists',
                'inProgress',
                'error',
                'dateIndexed',
            ], [], false)
        );

        $db = Craft::$app->getImageTransforms()->db;
        if ($index->id !== null) {
            Db::update(Table::IMAGETRANSFORMINDEX, $values, [
                'id' => $index->id,
            ], [], true, $db);
        } else {
            Db::insert(Table::IMAGETRANSFORMINDEX, $values, $db);
            $index->id = (int)$db->getLastInsertID(Table::IMAGETRANSFORMINDEX);
        }

        // todo: this should return void
        return $index;
    }

    /**
     * Returns a list of pending transform index IDs.
     *
     * @return array
     */
    public function getPendingTransformIndexIds(): array
    {
        return $this->_createTransformIndexQuery()
            ->select(['id'])
            ->where(['fileExists' => false, 'inProgress' => false, 'error' => false])
            ->column();
    }

    /**
     * Get a transform index model by a row id.
     *
     * @param int $transformId
     * @return ImageTransformIndex|null
     */
    public function getTransformIndexModelById(int $transformId): ?ImageTransformIndex
    {
        $result = $this->_createTransformIndexQuery()
            ->where(['id' => $transformId])
            ->one();

        return $result ? new ImageTransformIndex($result) : null;
    }

    /**
     * @inheritdoc
     */
    public function startImageEditing(Asset $asset): void
    {
        $imageCopy = $asset->getCopyOfFile();

        /** @var Raster $image */
        $image = Craft::$app->getImages()->loadImage($imageCopy, true, max($asset->height, $asset->width));

        // TODO Is this hacky? It seems hacky.
        // We're rasterizing SVG, we have to make sure that the filename change does not get lost
        if (strtolower($asset->getExtension()) === 'svg') {
            unlink($imageCopy);
            $imageCopy = preg_replace('/(svg)$/i', 'png', $imageCopy);
            $asset->setFilename(preg_replace('/(svg)$/i', 'png', $asset->getFilename()));
        }

        $this->imageEditorData['image'] = $image;
        $this->imageEditorData['tempLocation'] = $imageCopy;
    }

    /**
     * @inheritdoc
     */
    public function flipImage(bool $flipX, bool $flipY): void
    {
        if ($flipX) {
            $this->imageEditorData['image']->flipHorizontally();
        }
        if ($flipY) {
            $this->imageEditorData['image']->flipVertically();
        }
    }

    /**
     * @inheritdoc
     */
    public function scaleImage(int $width, int $height): void
    {
        $this->imageEditorData['image']->scaleToFit($width, $height);
    }

    /**
     * @inheritdoc
     */
    public function rotateImage(float $degrees): void
    {
        $this->imageEditorData['image']->rotate($degrees);
    }

    /**
     * @inheritdoc
     */
    public function getEditedImageWidth(): int
    {
        return $this->imageEditorData['image']->getWidth();
    }

    /**
     * @inheritdoc
     */
    public function getEditedImageHeight(): int
    {
        return $this->imageEditorData['image']->getHeight();
    }

    /**
     * @inheritdoc
     */
    public function crop(int $x, int $y, int $width, int $height): void
    {
        $this->imageEditorData['image']->crop($x, $x + $width, $y, $y + $height);
    }

    /**
     * @inheritdoc
     */
    public function finishImageEditing(): string
    {
        $tempLocation = $this->imageEditorData['tempLocation'];
        $this->imageEditorData['image']->saveAs($tempLocation);
        $this->imageEditorData = [];

        return $tempLocation;
    }

    /**
     * @inheritdoc
     */
    public function cancelImageEditing(): string
    {
        $tempLocation = $this->imageEditorData['tempLocation'];
        $this->imageEditorData = [];
        return $tempLocation;
    }

    /**
     * Get the transform base path for a given asset.
     *
     * @param Asset $asset
     * @return string
     * @throws InvalidConfigException
     */
    protected function getTransformBasePath(Asset $asset): string
    {
        $subPath = $asset->getVolume()->transformSubpath;
        $subPath = StringHelper::removeRight($subPath, '/');
        return ($subPath ? $subPath . DIRECTORY_SEPARATOR : '') . $asset->folderPath;
    }

    /**
     * Delete transform records by an Asset id
     *
     * @param int $assetId
     */
    protected function deleteTransformIndexDataByAssetId(int $assetId): void
    {
        Db::delete(Table::IMAGETRANSFORMINDEX, [
            'assetId' => $assetId,
        ], [], Craft::$app->getImageTransforms()->db);
    }

    /**
     * Get an array of ImageTransformIndex models for all created transforms for an Asset.
     *
     * @param Asset $asset
     * @return ImageTransformIndex[]
     */
    protected function getAllCreatedTransformsForAsset(Asset $asset): array
    {
        $results = $this->_createTransformIndexQuery()
            ->where(['assetId' => $asset->id])
            ->all();

        foreach ($results as $key => $result) {
            $results[$key] = new ImageTransformIndex($result);
        }

        return $results;
    }

    /**
     * Find a similar image transform for reuse for an asset and existing transform.
     *
     * @param Asset $asset
     * @param ImageTransformIndex $index
     * @return ImageTransformIndex|null
     * @throws InvalidConfigException
     */
    protected function getSimilarTransformIndex(Asset $asset, ImageTransformIndex $index): ?ImageTransformIndex
    {
        $transform = $index->getTransform();
        $result = null;

        if ($asset->getExtension() === $index->detectedFormat && !$asset->getHasFocalPoint()) {
            $possibleLocations = [TransformHelper::getTransformString($transform, true)];

            if ($transform->getIsNamedTransform()) {
                $namedLocation = TransformHelper::getTransformString($transform);
                $possibleLocations[] = $namedLocation;
            }

            // We're looking for transforms that fit the bill and are not the one we are trying to find/create
            // the image for.
            $result = $this->_createTransformIndexQuery()
                ->where([
                    'and',
                    [
                        'assetId' => $asset->id,
                        'fileExists' => true,
                        'transformString' => $possibleLocations,
                        'format' => $index->detectedFormat,
                    ],
                    ['not', ['id' => $index->id]],
                ])
                ->one();
        }

        return $result ? Craft::createObject(array_merge(['class' => ImageTransformIndex::class], $result)) : null;
    }

    /**
     * Returns a Query object prepped for retrieving transform indexes.
     *
     * @return Query
     */
    private function _createTransformIndexQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'assetId',
                'filename',
                'format',
                'transformString',
                'fileExists',
                'inProgress',
                'error',
                'dateIndexed',
                'dateUpdated',
                'dateCreated',
            ])
            ->from([Table::IMAGETRANSFORMINDEX]);
    }
}
