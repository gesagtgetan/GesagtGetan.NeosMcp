<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindAndReplaceMatch implements \JsonSerializable
{
    public function __construct(
        public string $nodeAggregateId,
        public string $nodeTypeName,
        public string $propertyName,
        public string $oldValue,
        public string $newValue,
    ) {
    }

    /**
     * @return array{nodeAggregateId: string, nodeTypeName: string, propertyName: string, oldValue: string, newValue: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodeAggregateId' => $this->nodeAggregateId,
            'nodeTypeName' => $this->nodeTypeName,
            'propertyName' => $this->propertyName,
            'oldValue' => $this->oldValue,
            'newValue' => $this->newValue,
        ];
    }
}
