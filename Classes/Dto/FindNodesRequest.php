<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindNodesRequest
{
    public const MAX_LIMIT = 500;

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function __construct(
        public ?string $nodeTypeName,
        public ?string $searchTerm,
        public ?string $parentNodeAggregateId,
        public int $limit,
        public ?array $dimensionSpacePoint,
        public bool $includeRemoved,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('FindNodesRequest.limit must be between 1 and %d, got %d', self::MAX_LIMIT, $limit), 1779900010);
        }
        if ($parentNodeAggregateId !== null && $parentNodeAggregateId === '') {
            throw new \InvalidArgumentException('FindNodesRequest.parentNodeAggregateId must not be empty string (use null to omit)', 1779900011);
        }
    }
}
