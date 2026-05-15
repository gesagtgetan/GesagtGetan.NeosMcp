<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, NodeTypeSummary>
 */
#[Flow\Proxy(false)]
final readonly class NodeTypeSummaryCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<NodeTypeSummary> */
    private array $items;

    public function __construct(NodeTypeSummary ...$items)
    {
        $this->items = array_values($items);
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
     * @return \ArrayIterator<int, NodeTypeSummary>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<NodeTypeSummary>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
