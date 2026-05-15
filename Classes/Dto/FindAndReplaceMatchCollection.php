<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, FindAndReplaceMatch>
 */
#[Flow\Proxy(false)]
final readonly class FindAndReplaceMatchCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<FindAndReplaceMatch> */
    private array $items;

    public function __construct(FindAndReplaceMatch ...$items)
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
     * @return \ArrayIterator<int, FindAndReplaceMatch>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<FindAndReplaceMatch>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
