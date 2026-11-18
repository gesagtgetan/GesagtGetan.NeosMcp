<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\AudioRepository;
use Neos\Media\Domain\Repository\DocumentRepository;
use Neos\Media\Domain\Repository\ImageRepository;
use Neos\Media\Domain\Repository\VideoRepository;

/**
 * The asset kinds the Neos media library distinguishes, same as its type filter.
 */
enum AssetType: string
{
    case All = 'All';
    case Image = 'Image';
    case Document = 'Document';
    case Video = 'Video';
    case Audio = 'Audio';

    /**
     * Each kind has its own repository, which limits queries to that kind.
     *
     * @return class-string<AssetRepository>
     */
    public function repositoryClassName(): string
    {
        return match ($this) {
            self::All => AssetRepository::class,
            self::Image => ImageRepository::class,
            self::Document => DocumentRepository::class,
            self::Video => VideoRepository::class,
            self::Audio => AudioRepository::class,
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
