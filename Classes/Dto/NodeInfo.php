<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class NodeInfo implements \JsonSerializable, WithRebaseWarning
{
    /**
     * @param array<string, mixed> $properties free-form CR property payload; intentionally untyped
     */
    public function __construct(
        public string $nodeAggregateId,
        public string $nodeTypeName,
        public ?string $nodeName,
        public bool $hidden,
        public array $properties,
        public ?string $rebaseWarning = null,
    ) {
        if ($nodeAggregateId === '') {
            throw new \InvalidArgumentException('NodeInfo.nodeAggregateId must not be empty', 1779900001);
        }
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self(
            $this->nodeAggregateId,
            $this->nodeTypeName,
            $this->nodeName,
            $this->hidden,
            $this->properties,
            $warning,
        );
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>, _rebaseWarning?: string}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'nodeAggregateId' => $this->nodeAggregateId,
            'nodeTypeName' => $this->nodeTypeName,
            'nodeName' => $this->nodeName,
            'hidden' => $this->hidden,
            'properties' => $this->properties,
        ];

        if ($this->rebaseWarning !== null) {
            $payload['_rebaseWarning'] = $this->rebaseWarning;
        }

        return $payload;
    }
}
