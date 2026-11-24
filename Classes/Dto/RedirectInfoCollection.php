<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, RedirectInfo>
 */
#[Flow\Proxy(false)]
final readonly class RedirectInfoCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<RedirectInfo> */
    private array $items;

    public function __construct(RedirectInfo ...$items)
    {
        $this->items = array_values($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, RedirectInfo>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<RedirectInfo>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
