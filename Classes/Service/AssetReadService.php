<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\Dto\AssetInfo;
use GesagtGetan\NeosMcp\Dto\AssetInfoCollection;
use GesagtGetan\NeosMcp\Dto\AssetSearchResult;
use GesagtGetan\NeosMcp\Dto\FindAssetsRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;

/**
 * Searches the Neos media library.
 *
 * Assets live outside the Content Repository, so results are the same in every
 * workspace and dimension.
 */
#[Flow\Scope('singleton')]
final readonly class AssetReadService
{
    public function __construct(
        private ObjectManagerInterface $objectManager,
        private AssetCollectionRepository $assetCollectionRepository,
    ) {
    }

    public function findAssets(FindAssetsRequest $request): AssetSearchResult
    {
        $assetCollection = $request->collectionTitle === null
            ? null
            : $this->requireAssetCollection($request->collectionTitle);

        $assetRepository = $this->objectManager->get($request->assetType->repositoryClassName());
        $matchingAssets = $request->searchTerm === null
            ? $assetRepository->findAll($assetCollection)
            : $assetRepository->findBySearchTermOrTags($request->searchTerm, [], $assetCollection);

        $assetsOnPage = $matchingAssets->getQuery()
            ->setOrderings(['resource.filename' => QueryInterface::ORDER_ASCENDING])
            ->setLimit($request->limit)
            ->setOffset($request->offset)
            ->execute()
            ->toArray();

        return new AssetSearchResult(
            total: $matchingAssets->count(),
            limit: $request->limit,
            offset: $request->offset,
            assets: new AssetInfoCollection(...array_map(
                self::toAssetInfo(...),
                array_values(array_filter($assetsOnPage, static fn (mixed $asset): bool => $asset instanceof Asset)),
            )),
        );
    }

    private function requireAssetCollection(string $title): AssetCollection
    {
        $assetCollections = array_filter(
            $this->assetCollectionRepository->findAll()->toArray(),
            static fn (mixed $assetCollection): bool => $assetCollection instanceof AssetCollection,
        );
        foreach ($assetCollections as $assetCollection) {
            if (mb_strtolower($assetCollection->getTitle()) === mb_strtolower($title)) {
                return $assetCollection;
            }
        }

        $availableTitles = array_map(
            static fn (AssetCollection $assetCollection): string => $assetCollection->getTitle(),
            $assetCollections,
        );

        throw new \InvalidArgumentException(sprintf('No asset collection titled "%s". Available collections: %s', $title, $availableTitles === [] ? '(none)' : implode(', ', $availableTitles)), 1794960006);
    }

    private static function toAssetInfo(Asset $asset): AssetInfo
    {
        $resource = $asset->getResource();
        $tags = array_filter($asset->getTags()->toArray(), static fn (mixed $tag): bool => $tag instanceof Tag);
        $assetCollections = array_filter(
            $asset->getAssetCollections()->toArray(),
            static fn (mixed $assetCollection): bool => $assetCollection instanceof AssetCollection,
        );

        return new AssetInfo(
            assetIdentifier: $asset->getIdentifier(),
            filename: $resource->getFilename(),
            title: $asset->getTitle(),
            caption: $asset->getCaption(),
            mediaType: $asset->getMediaType(),
            fileSize: (int) $resource->getFileSize(),
            tags: array_values(array_map(static fn (Tag $tag): string => $tag->getLabel(), $tags)),
            collections: array_values(array_map(
                static fn (AssetCollection $assetCollection): string => $assetCollection->getTitle(),
                $assetCollections,
            )),
        );
    }
}
