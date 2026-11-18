<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * One page of a media library search, with what the caller needs to fetch the next one.
 */
#[Flow\Proxy(false)]
final readonly class AssetSearchResult implements \JsonSerializable
{
    public bool $hasMore;

    public function __construct(
        public int $total,
        public int $limit,
        public int $offset,
        public AssetInfoCollection $assets,
    ) {
        $this->hasMore = $offset + $assets->count() < $total;
    }

    /**
     * @return array{total: int, hasMore: bool, limit: int, offset: int, assets: AssetInfoCollection}
     */
    public function jsonSerialize(): array
    {
        return [
            'total' => $this->total,
            'hasMore' => $this->hasMore,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'assets' => $this->assets,
        ];
    }
}
