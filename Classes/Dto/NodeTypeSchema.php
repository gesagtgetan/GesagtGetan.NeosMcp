<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class NodeTypeSchema implements \JsonSerializable
{
    /**
     * @param list<string> $superTypes
     * @param array<string, string> $childNodes
     * @param array<string, mixed> $references
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $abstract,
        public bool $final,
        public array $superTypes,
        public PropertyDefinitionCollection $properties,
        public array $childNodes,
        public array $references,
    ) {
    }

    /**
     * @return array{name: string, label: string, abstract: bool, final: bool, superTypes: list<string>, properties: PropertyDefinitionCollection, childNodes: array<string, string>, references: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'abstract' => $this->abstract,
            'final' => $this->final,
            'superTypes' => $this->superTypes,
            'properties' => $this->properties,
            'childNodes' => $this->childNodes,
            'references' => $this->references,
        ];
    }
}
