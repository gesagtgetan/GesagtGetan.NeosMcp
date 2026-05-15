<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, WorkspaceInfo>
 */
#[Flow\Proxy(false)]
final readonly class WorkspaceInfoCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<WorkspaceInfo> */
    private array $items;

    public function __construct(WorkspaceInfo ...$items)
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
     * @return \ArrayIterator<int, WorkspaceInfo>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<WorkspaceInfo>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
