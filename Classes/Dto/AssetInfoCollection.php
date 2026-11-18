<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int, AssetInfo>
 */
#[Flow\Proxy(false)]
final readonly class AssetInfoCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var list<AssetInfo> */
    private array $items;

    public function __construct(AssetInfo ...$items)
    {
        $this->items = array_values($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return \ArrayIterator<int, AssetInfo>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<AssetInfo>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
