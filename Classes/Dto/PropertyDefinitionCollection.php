<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<string, PropertyDefinition>
 */
#[Flow\Proxy(false)]
final readonly class PropertyDefinitionCollection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var array<string, PropertyDefinition> */
    private array $items;

    public function __construct(PropertyDefinition ...$items)
    {
        $byName = [];
        foreach ($items as $item) {
            if (isset($byName[$item->name])) {
                throw new \InvalidArgumentException(sprintf('Duplicate PropertyDefinition name "%s"', $item->name), 1779900002);
            }
            $byName[$item->name] = $item;
        }
        $this->items = $byName;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function get(string $name): ?PropertyDefinition
    {
        return $this->items[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->items[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->items);
    }

    /**
     * @return \ArrayIterator<string, PropertyDefinition>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return array<string, PropertyDefinition>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
