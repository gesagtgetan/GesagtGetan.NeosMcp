<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<string, DimensionInfo>
 */
#[Flow\Proxy(false)]
final readonly class DimensionMap implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var array<string, DimensionInfo> */
    private array $items;

    public function __construct(DimensionInfo ...$dimensions)
    {
        $byId = [];
        foreach ($dimensions as $dimension) {
            if (isset($byId[$dimension->id])) {
                throw new \InvalidArgumentException(sprintf('Duplicate DimensionInfo id "%s"', $dimension->id), 1779900003);
            }
            $byId[$dimension->id] = $dimension;
        }
        $this->items = $byId;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function get(string $id): ?DimensionInfo
    {
        return $this->items[$id] ?? null;
    }

    /**
     * @return \ArrayIterator<string, DimensionInfo>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return array<string, array{values: list<string>}>
     */
    public function jsonSerialize(): array
    {
        $result = [];
        foreach ($this->items as $id => $dimension) {
            $result[$id] = ['values' => $dimension->values];
        }

        return $result;
    }
}
