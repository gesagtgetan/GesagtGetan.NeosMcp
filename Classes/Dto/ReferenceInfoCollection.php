<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, ReferenceInfo>
 */
#[Flow\Proxy(false)]
final readonly class ReferenceInfoCollection implements \Countable, \IteratorAggregate, \JsonSerializable, WithRebaseWarning
{
    /** @var list<ReferenceInfo> */
    private array $items;

    /**
     * @param list<ReferenceInfo> $items
     */
    private function __construct(
        array $items,
        public ?string $rebaseWarning = null,
    ) {
        $this->items = $items;
    }

    public static function create(ReferenceInfo ...$items): self
    {
        return new self(array_values($items));
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return \ArrayIterator<int, ReferenceInfo>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self($this->items, $warning);
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return list<ReferenceInfo>|array{references: list<ReferenceInfo>, _rebaseWarning: string}
     */
    public function jsonSerialize(): array
    {
        if ($this->rebaseWarning !== null) {
            return [
                'references' => $this->items,
                '_rebaseWarning' => $this->rebaseWarning,
            ];
        }

        return $this->items;
    }
}
