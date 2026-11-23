<?php

namespace teachmeter\mediaproxy;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\ImageTransforms;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransformIndex;

/**
 * Builds the URL path of a transform the remote has already generated.
 * Extends Craft's transformer only to reuse its protected path helpers, so paths match the remote exactly.
 */
class TransformPath extends ImageTransformer
{
    /**
     * URL path of the transform file, or null when the cloned transform index says it was never generated.
     * Read-only: unlike ImageTransformer::getTransformIndex() it never creates, fixes or deletes index rows.
     */
    public function find(Asset $asset, mixed $transform): ?string
    {
        $transform = ImageTransforms::normalizeTransform($transform);
        $rootUrl = $asset->getVolume()->getTransformFs()->getRootUrl();
        if ($transform === null || $rootUrl === null) {
            return null;
        }

        $row = (new Query())
            ->select(['id', 'assetId', 'filename', 'format', 'transformString'])
            ->from(Table::IMAGETRANSFORMINDEX)
            ->where([
                'assetId' => $asset->id,
                'transformString' => ImageTransforms::getTransformString($transform),
                'format' => $transform->format,
                'fileExists' => true,
            ])
            ->one(Craft::$app->getImageTransforms()->db);

        if (!$row) {
            return null;
        }

        $uri = str_replace('\\', '/', $this->getTransformBasePath($asset)) . $this->getTransformUri($asset, new ImageTransformIndex($row));

        return rtrim((string) parse_url($rootUrl, PHP_URL_PATH), '/') . '/' . $uri;
    }
}
