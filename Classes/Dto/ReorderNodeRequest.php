<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ReorderNodeRequest
{
    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function __construct(
        public string $nodeAggregateId,
        public ?string $placeBeforeNodeAggregateId,
        public ?string $placeAfterNodeAggregateId,
        public ?array $dimensionSpacePoint,
    ) {
        if ($nodeAggregateId === '') {
            throw new \InvalidArgumentException('ReorderNodeRequest.nodeAggregateId must not be empty', 1779900020);
        }
        if ($placeBeforeNodeAggregateId === null && $placeAfterNodeAggregateId === null) {
            throw new \InvalidArgumentException('ReorderNodeRequest requires at least one of placeBeforeNodeAggregateId or placeAfterNodeAggregateId', 1779900021);
        }
    }
}
