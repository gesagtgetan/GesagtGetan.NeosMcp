<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

interface WithRebaseWarning
{
    public function withRebaseWarning(?string $warning): static;

    public function getRebaseWarning(): ?string;
}
