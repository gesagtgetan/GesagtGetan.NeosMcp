<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class AssetInfo implements \JsonSerializable
{
    /**
     * @param string $assetIdentifier
     * @param string $filename
     * @param string $title
     * @param string $caption
     * @param string $mediaType
     * @param int $fileSize Size in bytes
     * @param list<string> $tags Tag labels
     * @param list<string> $collections Asset collection titles
     */
    public function __construct(
        public string $assetIdentifier,
        public string $filename,
        public string $title,
        public string $caption,
        public string $mediaType,
        public int $fileSize,
        public array $tags,
        public array $collections,
    ) {
    }

    /**
     * @return array{assetIdentifier: string, filename: string, title: string, caption: string, mediaType: string, fileSize: int, tags: list<string>, collections: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'assetIdentifier' => $this->assetIdentifier,
            'filename' => $this->filename,
            'title' => $this->title,
            'caption' => $this->caption,
            'mediaType' => $this->mediaType,
            'fileSize' => $this->fileSize,
            'tags' => $this->tags,
            'collections' => $this->collections,
        ];
    }
}
