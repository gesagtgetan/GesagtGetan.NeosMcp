<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, array<string, string>>
 */
#[Flow\Proxy(false)]
final readonly class DimensionSpacePointList implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<array<string, string>> */
    private array $items;

    /**
     * @param list<array<string, string>> $coordinates each entry is a coordinate map for one dimension space point
     */
    public function __construct(array $coordinates)
    {
        $this->items = array_values($coordinates);
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
     * @return \ArrayIterator<int, array<string, string>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<array<string, string>>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
