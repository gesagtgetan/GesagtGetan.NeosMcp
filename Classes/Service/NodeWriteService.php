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
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
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
        private ?int $propertyTruncateLength = null,
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

    /**
     * Hides a node by tagging it as disabled. The node will not be visible on the public site.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    public function hideNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): array {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = TagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::disabled(),
        );

        $this->contentRepository->handle($command);

        return [
            'nodeAggregateId' => $nodeAggregateId,
            'success' => true,
        ];
    }

    /**
     * Unhides a previously hidden node by removing the disabled tag.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, success: true}
     */
    public function unhideNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): array {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = UntagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::disabled(),
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
     * @return array{affectedNodes: int, matches: list<array{nodeAggregateId: string, nodeTypeName: string, propertyName: string, oldValue: string, newValue: string}>, dryRun: bool} oldValue/newValue are context snippets (~80 chars around match), not full values
     */
    public function findAndReplace(
        string $search,
        string $replace,
        ?string $nodeTypeName = null,
        ?string $propertyName = null,
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
            $propertiesToReplace = [];

            if ($propertyName !== null) {
                $currentValue = $node->getProperty($propertyName);
                if (is_string($currentValue) && str_contains($currentValue, $search)) {
                    $propertiesToReplace[$propertyName] = $currentValue;
                }
            } else {
                foreach ($node->properties as $name => $value) {
                    if (is_string($value) && str_contains($value, $search)) {
                        $propertiesToReplace[$name] = $value;
                    }
                }
            }

            if ($propertiesToReplace === []) {
                continue;
            }

            $updatedProperties = [];
            foreach ($propertiesToReplace as $name => $currentValue) {
                $newValue = str_replace($search, $replace, $currentValue);
                $matches[] = [
                    'nodeAggregateId' => $node->aggregateId->value,
                    'nodeTypeName' => $node->nodeTypeName->value,
                    'propertyName' => $name,
                    'oldValue' => self::truncateString($currentValue, $this->propertyTruncateLength),
                    'newValue' => self::truncateString($newValue, $this->propertyTruncateLength),
                ];
                $updatedProperties[$name] = $newValue;
            }

            if (!$dryRun) {
                $command = SetNodeProperties::create(
                    $this->workspaceName,
                    $node->aggregateId,
                    $originDsp,
                    PropertyValuesToWrite::fromArray($updatedProperties),
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

    private static function truncateString(string $value, ?int $maxLength): string
    {
        if ($maxLength === null || mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '…';
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
