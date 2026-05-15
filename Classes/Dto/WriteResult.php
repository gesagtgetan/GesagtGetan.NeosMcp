<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class WriteResult implements \JsonSerializable, WithRebaseWarning
{
    public function __construct(
        public string $nodeAggregateId,
        public ?string $rebaseWarning = null,
    ) {
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self($this->nodeAggregateId, $warning);
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return array{nodeAggregateId: string, success: true, _rebaseWarning?: string}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'nodeAggregateId' => $this->nodeAggregateId,
            'success' => true,
        ];

        if ($this->rebaseWarning !== null) {
            $payload['_rebaseWarning'] = $this->rebaseWarning;
        }

        return $payload;
    }
}
