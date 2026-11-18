<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindAssetsRequest
{
    public const MAX_LIMIT = 100;

    public AssetType $assetType;

    public function __construct(
        public ?string $searchTerm,
        string $assetType,
        public ?string $collectionTitle,
        public int $limit,
        public int $offset,
    ) {
        if ($searchTerm === '') {
            throw new \InvalidArgumentException('FindAssetsRequest.searchTerm must not be empty string (use null to list all assets)', 1794960001);
        }
        $this->assetType = AssetType::tryFrom($assetType)
            ?? throw new \InvalidArgumentException(sprintf('FindAssetsRequest.assetType must be one of %s, got "%s"', implode(', ', AssetType::values()), $assetType), 1794960002);
        if ($collectionTitle === '') {
            throw new \InvalidArgumentException('FindAssetsRequest.collectionTitle must not be empty string (use null to omit)', 1794960003);
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('FindAssetsRequest.limit must be between 1 and %d, got %d', self::MAX_LIMIT, $limit), 1794960004);
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('FindAssetsRequest.offset must be 0 or greater, got %d', $offset), 1794960005);
        }
    }
}
