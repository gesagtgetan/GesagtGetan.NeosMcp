<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class PropertyDefinition implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $validation
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $defaultValue,
        public ?string $label = null,
        public ?string $description = null,
        public ?array $validation = null,
    ) {
    }

    /**
     * @return array{type: string, defaultValue: mixed, label?: string, description?: string, validation?: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'type' => $this->type,
            'defaultValue' => $this->defaultValue,
        ];

        if ($this->label !== null) {
            $payload['label'] = $this->label;
        }

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        if ($this->validation !== null) {
            $payload['validation'] = $this->validation;
        }

        return $payload;
    }
}
