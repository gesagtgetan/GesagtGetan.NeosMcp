<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Dto;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ContentRepositoryInfo implements \JsonSerializable, WithRebaseWarning
{
    public function __construct(
        public string $contentRepositoryId,
        public DimensionMap $dimensions,
        public WorkspaceInfoCollection $workspaces,
        public DimensionSpacePointList $dimensionSpacePoints,
        public ?string $rebaseWarning = null,
    ) {
    }

    public function withRebaseWarning(?string $warning): static
    {
        return new self(
            $this->contentRepositoryId,
            $this->dimensions,
            $this->workspaces,
            $this->dimensionSpacePoints,
            $warning,
        );
    }

    public function getRebaseWarning(): ?string
    {
        return $this->rebaseWarning;
    }

    /**
     * @return array{contentRepositoryId: string, dimensions: DimensionMap, workspaces: WorkspaceInfoCollection, dimensionSpacePoints: DimensionSpacePointList, _rebaseWarning?: string}
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'contentRepositoryId' => $this->contentRepositoryId,
            'dimensions' => $this->dimensions,
            'workspaces' => $this->workspaces,
            'dimensionSpacePoints' => $this->dimensionSpacePoints,
        ];

        if ($this->rebaseWarning !== null) {
            $payload['_rebaseWarning'] = $this->rebaseWarning;
        }

        return $payload;
    }
}
