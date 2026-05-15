<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class FindAndReplaceResult implements \JsonSerializable, WithRebaseWarning
{
    public int $affectedNodes;

    public function __construct(
        public FindAndReplaceMatchCollection $matches,
        public bool $dryRun,
        public ?string $rebaseWarning = null,
    ) {
        $this->affectedNodes = $matches->count();
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self($this->matches, $this->dryRun, $warning);
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return array{affectedNodes: int, matches: FindAndReplaceMatchCollection, dryRun: bool, _rebaseWarning?: string}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'affectedNodes' => $this->affectedNodes,
            'matches' => $this->matches,
            'dryRun' => $this->dryRun,
        ];

        if ($this->rebaseWarning !== null) {
            $payload['_rebaseWarning'] = $this->rebaseWarning;
        }

        return $payload;
    }
}
