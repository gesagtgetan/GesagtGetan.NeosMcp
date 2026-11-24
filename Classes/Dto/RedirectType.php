<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\RedirectHandler\RedirectInterface;

/**
 * How a redirect came about, same as the type filter of the Neos redirect module.
 */
enum RedirectType: string
{
    case All = 'All';
    case Manual = 'Manual';
    case Generated = 'Generated';

    /**
     * The type as the redirect storage filters it, `null` for no filter.
     */
    public function storageType(): ?string
    {
        return match ($this) {
            self::All => null,
            self::Manual => RedirectInterface::REDIRECT_TYPE_MANUAL,
            self::Generated => RedirectInterface::REDIRECT_TYPE_GENERATED,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
