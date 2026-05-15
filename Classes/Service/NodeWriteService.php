<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceMatch;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceMatchCollection;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceRequest;
use GesagtGetan\NeosMcp\Dto\FindAndReplaceResult;
use GesagtGetan\NeosMcp\Dto\MoveResult;
use GesagtGetan\NeosMcp\Dto\ReorderNodeRequest;
use GesagtGetan\NeosMcp\Dto\WriteResult;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
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
     */
    public function createNode(
        string $parentNodeAggregateId,
        string $nodeTypeName,
        array $properties,
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
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

        return new WriteResult(nodeAggregateId: $nodeAggregateId->value);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function setNodeProperties(
        string $nodeAggregateId,
        array $properties,
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $originDimensionSpacePoint = $this->resolveOriginDimensionSpacePoint($dimensionSpacePoint);

        $command = SetNodeProperties::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $originDimensionSpacePoint,
            PropertyValuesToWrite::fromArray($properties),
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $nodeAggregateId);
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function moveNode(
        string $nodeAggregateId,
        string $newParentNodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): MoveResult {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = MoveNodeAggregate::create(
            $this->workspaceName,
            $dsp,
            NodeAggregateId::fromString($nodeAggregateId),
            RelationDistributionStrategy::default(),
            NodeAggregateId::fromString($newParentNodeAggregateId),
        );

        $this->contentRepository->handle($command);

        return new MoveResult(
            nodeAggregateId: $nodeAggregateId,
            newParentNodeAggregateId: $newParentNodeAggregateId,
        );
    }

    /**
     * Materialize a node into a different dimension space point.
     *
     * Required before writes against a target DSP can succeed if the node does
     * not yet exist as a variant there. The CR picks the variant strategy
     * (peer / specialize / generalize) from the dimension topology — callers
     * don't choose.
     *
     * @param array<string, string> $sourceDimensionSpacePoint
     * @param array<string, string> $targetDimensionSpacePoint
     */
    public function createNodeVariant(
        string $nodeAggregateId,
        array $sourceDimensionSpacePoint,
        array $targetDimensionSpacePoint,
    ): WriteResult {
        $command = CreateNodeVariant::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            OriginDimensionSpacePoint::fromArray($sourceDimensionSpacePoint),
            OriginDimensionSpacePoint::fromArray($targetDimensionSpacePoint),
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $nodeAggregateId);
    }

    /**
     * Reorders a node within its current parent by placing it relative to a sibling.
     * The parent is not changed; the {@see ReorderNodeRequest} enforces that at least
     * one of `placeBeforeNodeAggregateId` or `placeAfterNodeAggregateId` is provided.
     */
    public function reorderNode(ReorderNodeRequest $request): WriteResult
    {
        $dsp = $this->resolveDimensionSpacePoint($request->dimensionSpacePoint);

        $command = MoveNodeAggregate::create(
            $this->workspaceName,
            $dsp,
            NodeAggregateId::fromString($request->nodeAggregateId),
            RelationDistributionStrategy::default(),
            newParentNodeAggregateId: null,
            newPrecedingSiblingNodeAggregateId: $request->placeAfterNodeAggregateId !== null
                ? NodeAggregateId::fromString($request->placeAfterNodeAggregateId)
                : null,
            newSucceedingSiblingNodeAggregateId: $request->placeBeforeNodeAggregateId !== null
                ? NodeAggregateId::fromString($request->placeBeforeNodeAggregateId)
                : null,
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $request->nodeAggregateId);
    }

    /**
     * Soft-removes a node by tagging it as removed (trash). The node can be restored later.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function removeNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = TagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::removed(),
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $nodeAggregateId);
    }

    /**
     * Hides a node by tagging it as disabled. The node will not be visible on the public site.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function hideNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = TagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::disabled(),
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $nodeAggregateId);
    }

    /**
     * Unhides a previously hidden node by removing the disabled tag.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function unhideNode(
        string $nodeAggregateId,
        ?array $dimensionSpacePoint = null,
    ): WriteResult {
        $dsp = $this->resolveDimensionSpacePoint($dimensionSpacePoint);

        $command = UntagSubtree::create(
            $this->workspaceName,
            NodeAggregateId::fromString($nodeAggregateId),
            $dsp,
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            NeosSubtreeTag::disabled(),
        );

        $this->contentRepository->handle($command);

        return new WriteResult(nodeAggregateId: $nodeAggregateId);
    }

    /**
     * Find and replace a string across the content tree.
     *
     * `oldValue`/`newValue` in each match are context snippets (~80 chars around match),
     * not full values.
     */
    public function findAndReplace(FindAndReplaceRequest $request): FindAndReplaceResult
    {
        $dsp = $this->resolveDimensionSpacePoint($request->dimensionSpacePoint);
        $originDsp = OriginDimensionSpacePoint::fromDimensionSpacePoint($dsp);
        $subgraph = $this->contentRepository->getContentGraph($this->workspaceName)
            ->getSubgraph($dsp, NeosVisibilityConstraints::excludeRemoved());

        $sitesRoot = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));
        if ($sitesRoot === null) {
            return new FindAndReplaceResult(matches: new FindAndReplaceMatchCollection(), dryRun: $request->dryRun);
        }

        $filter = FindDescendantNodesFilter::create(nodeTypes: $request->nodeTypeName);
        $nodes = $subgraph->findDescendantNodes($sitesRoot->aggregateId, $filter);

        $matches = [];
        foreach ($nodes as $node) {
            $propertiesToReplace = [];

            if ($request->propertyName !== null) {
                $currentValue = $node->getProperty($request->propertyName);
                if (is_string($currentValue) && str_contains($currentValue, $request->search)) {
                    $propertiesToReplace[$request->propertyName] = $currentValue;
                }
            } else {
                foreach ($node->properties as $name => $value) {
                    if (is_string($value) && str_contains($value, $request->search)) {
                        $propertiesToReplace[$name] = $value;
                    }
                }
            }

            if ($propertiesToReplace === []) {
                continue;
            }

            $updatedProperties = [];
            foreach ($propertiesToReplace as $name => $currentValue) {
                $newValue = str_replace($request->search, $request->replace, $currentValue);
                $matches[] = new FindAndReplaceMatch(
                    nodeAggregateId: $node->aggregateId->value,
                    nodeTypeName: $node->nodeTypeName->value,
                    propertyName: $name,
                    oldValue: self::truncateString($currentValue, $this->propertyTruncateLength),
                    newValue: self::truncateString($newValue, $this->propertyTruncateLength),
                );
                $updatedProperties[$name] = $newValue;
            }

            if (!$request->dryRun) {
                $command = SetNodeProperties::create(
                    $this->workspaceName,
                    $node->aggregateId,
                    $originDsp,
                    PropertyValuesToWrite::fromArray($updatedProperties),
                );

                $this->contentRepository->handle($command);
            }
        }

        return new FindAndReplaceResult(
            matches: new FindAndReplaceMatchCollection(...$matches),
            dryRun: $request->dryRun,
        );
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
