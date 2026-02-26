<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

#[Flow\Proxy(false)]
final readonly class NodeWriteService
{
    public function __construct(
        private ContentRepositoryFacade $contentRepository,
        private WorkspaceName $workspaceName,
    ) {
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    public function createNode(
        string $parentNodeAggregateId,
        string $nodeTypeName,
        array $properties,
        ?array $dimensionSpacePoint = null,
    ): array {
        $nodeAggregateId = NodeAggregateId::create();
        $originDimensionSpacePoint = $this->resolveOriginDimensionSpacePoint($dimensionSpacePoint);

        $command = CreateNodeAggregateWithNode::create(
            $this->workspaceName,
            $nodeAggregateId,
            NodeTypeName::fromString($nodeTypeName),
            $originDimensionSpacePoint,
            NodeAggregateId::fromString($parentNodeAggregateId),
        );

        if ($properties !== []) {
            $command = $command->withInitialPropertyValues(PropertyValuesToWrite::fromArray($properties));
        }

        $this->contentRepository->handle($command);

        return [
            'nodeAggregateId' => $nodeAggregateId->value,
            'success' => true,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    public function setNodeProperties(
        string $nodeAggregateId,
        array $properties,
        ?array $dimensionSpacePoint = null,
    ): array {
        $originDimensionSpacePoint = $this->resolveOriginDimensionSpacePoint($dimensionSpacePoint);

        $command = SetNodeProperties::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $originDimensionSpacePoint,
            PropertyValuesToWrite::fromArray($properties),
        );

        $this->contentRepository->handle($command);

        return [
            'nodeAggregateId' => $nodeAggregateId,
            'success' => true,
        ];
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, newParentNodeAggregateId: string, success: true}
     */
    public function moveNode(
        string $nodeAggregateId,
        string $newParentNodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): array {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = MoveNodeAggregate::create(
            $this->workspaceName,
            $dsp,
            NodeAggregateId::fromString($nodeAggregateId),
            RelationDistributionStrategy::default(),
            NodeAggregateId::fromString($newParentNodeAggregateId),
        );

        $this->contentRepository->handle($command);

        return [
            'nodeAggregateId' => $nodeAggregateId,
            'newParentNodeAggregateId' => $newParentNodeAggregateId,
            'success' => true,
        ];
    }

    /**
     * Soft-removes a node by tagging it as removed (trash). The node can be restored later.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    public function removeNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): array {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = TagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::removed(),
        );

        $this->contentRepository->handle($command);

        return [
            'nodeAggregateId' => $nodeAggregateId,
            'success' => true,
        ];
    }

    // TODO: Search across all string properties (regardless of property name) so the LLM
    //       doesn't need to call this once per property. Currently requires knowing the exact
    //       property name, which means multiple calls for the same search term across different
    //       node types / properties.

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{affectedNodes: int, matches: list<array{nodeAggregateId: string, oldValue: mixed, newValue: string}>, dryRun: bool}
     */
    public function findAndReplaceProperty(
        string $nodeTypeName,
        string $propertyName,
        string $search,
        string $replace,
        bool $dryRun = false,
        ?array $dimensionSpacePoint = null,
    ): array {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);
        $originDsp = OriginDimensionSpacePoint::fromDimensionSpacePoint($dsp);
        $subgraph = $this->contentRepository->getContentGraph($this->workspaceName)
            ->getSubgraph($dsp, NeosVisibilityConstraints::excludeRemoved());

        $sitesRoot = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));
        if ($sitesRoot === null) {
            return ['affectedNodes' => 0, 'matches' => [], 'dryRun' => $dryRun];
        }

        $filter = FindDescendantNodesFilter::create(nodeTypes: $nodeTypeName);
        $nodes = $subgraph->findDescendantNodes($sitesRoot->aggregateId, $filter);

        $matches = [];
        foreach ($nodes as $node) {
            $currentValue = $node->getProperty($propertyName);
            if (!is_string($currentValue)) {
                continue;
            }

            if (!str_contains($currentValue, $search)) {
                continue;
            }

            $newValue = str_replace($search, $replace, $currentValue);
            $matches[] = [
                'nodeAggregateId' => $node->aggregateId->value,
                'oldValue' => $currentValue,
                'newValue' => $newValue,
            ];

            if (!$dryRun) {
                $command = SetNodeProperties::create(
                    $this->workspaceName,
                    $node->aggregateId,
                    $originDsp,
                    PropertyValuesToWrite::fromArray([$propertyName => $newValue]),
                );

                $this->contentRepository->handle($command);
            }
        }

        return [
            'affectedNodes' => count($matches),
            'matches' => $matches,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    private function resolveDimensionSpacePoint(?array $dimensionSpacePoint): DimensionSpacePoint
    {
        if ($dimensionSpacePoint !== null) {
            return DimensionSpacePoint::fromArray($dimensionSpacePoint);
        }

        return $this->resolveDefaultDimensionSpacePoint();
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    private function resolveOriginDimensionSpacePoint(?array $dimensionSpacePoint): OriginDimensionSpacePoint
    {
        return OriginDimensionSpacePoint::fromDimensionSpacePoint(
            $this->resolveDimensionSpacePoint($dimensionSpacePoint),
        );
    }

    private function resolveDefaultDimensionSpacePoint(): DimensionSpacePoint
    {
        foreach ($this->contentRepository->getDimensionSpacePoints() as $point) {
            return $point;
        }

        return DimensionSpacePoint::createWithoutDimensions();
    }
}
