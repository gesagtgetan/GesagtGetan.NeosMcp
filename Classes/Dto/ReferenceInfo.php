<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ReferenceInfo implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $properties edge-properties on the reference itself, empty when the reference type declares none
     */
    public function __construct(
        public string $referenceName,
        public NodeInfo $target,
        public array $properties,
    ) {
        if ($referenceName === '') {
            throw new \InvalidArgumentException('ReferenceInfo.referenceName must not be empty', 1779900100);
        }
    }

    /**
     * @return array{referenceName: string, target: NodeInfo, properties: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'referenceName' => $this->referenceName,
            'target' => $this->target,
            'properties' => $this->properties,
        ];
    }
}
