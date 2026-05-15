<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class NodeTypeSummary implements \JsonSerializable
{
    /**
     * @param list<string> $superTypes
     * @param list<string> $declaredProperties
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $abstract,
        public bool $final,
        public array $superTypes,
        public array $declaredProperties,
    ) {
    }

    /**
     * @return array{name: string, label: string, abstract: bool, final: bool, superTypes: list<string>, declaredProperties: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'abstract' => $this->abstract,
            'final' => $this->final,
            'superTypes' => $this->superTypes,
            'declaredProperties' => $this->declaredProperties,
        ];
    }
}
