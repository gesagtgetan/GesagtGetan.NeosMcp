<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\ContentRepositoryInfo;
use GesagtGetan\NeosMcp\Dto\DimensionInfo;
use GesagtGetan\NeosMcp\Dto\DimensionMap;
use GesagtGetan\NeosMcp\Dto\DimensionSpacePointList;
use GesagtGetan\NeosMcp\Dto\FindNodesRequest;
use GesagtGetan\NeosMcp\Dto\NodeInfo;
use GesagtGetan\NeosMcp\Dto\NodeInfoCollection;
use GesagtGetan\NeosMcp\Dto\ReferenceInfo;
use GesagtGetan\NeosMcp\Dto\ReferenceInfoCollection;
use GesagtGetan\NeosMcp\Dto\WorkspaceInfo;
use GesagtGetan\NeosMcp\Dto\WorkspaceInfoCollection;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Reference;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

#[Flow\Proxy(false)]
final readonly class NodeReadService
{
    public function __construct(
        private ContentRepositoryFacade $contentRepository,
        private WorkspaceName $workspaceName,
        private ?int $propertyTruncateLength = null,
    ) {
    }

    public function getContentRepositoryInfo(): ContentRepositoryInfo
    {
        $dimensionSource = $this->contentRepository->getContentDimensionSource();
        $dimensions = [];
        foreach ($dimensionSource->getContentDimensionsOrderedByPriority() as $dimension) {
            $values = [];
            foreach ($dimension->values as $dimensionValue) {
                $values[] = $dimensionValue->value;
            }
            $dimensions[] = new DimensionInfo(id: $dimension->id->value, values: $values);
        }

        $dimensionSpacePoints = [];
        foreach ($this->contentRepository->getDimensionSpacePoints() as $dsp) {
            $dimensionSpacePoints[] = $dsp->coordinates;
        }

        $workspaces = [];
        foreach ($this->contentRepository->findWorkspaces() as $workspace) {
            $workspaces[] = new WorkspaceInfo(
                name: $workspace->workspaceName->value,
                baseWorkspace: $workspace->baseWorkspaceName?->value,
                status: $workspace->status->value,
            );
        }

        return new ContentRepositoryInfo(
            contentRepositoryId: $this->contentRepository->getId()->value,
            dimensions: new DimensionMap(...$dimensions),
            workspaces: new WorkspaceInfoCollection(...$workspaces),
            dimensionSpacePoints: new DimensionSpacePointList($dimensionSpacePoints),
        );
    }

    public function findNodes(FindNodesRequest $request): NodeInfoCollection
    {
        $subgraph = $this->getSubgraph($request->dimensionSpacePoint, $request->includeRemoved);

        $entryNodeId = $request->parentNodeAggregateId !== null
            ? NodeAggregateId::fromString($request->parentNodeAggregateId)
            : $this->findSitesRootNodeId($subgraph);

        if ($entryNodeId === null) {
            return new NodeInfoCollection();
        }

        $filter = FindDescendantNodesFilter::create(
            nodeTypes: $request->nodeTypeName,
            searchTerm: $request->searchTerm,
            pagination: Pagination::fromLimitAndOffset($request->limit, 0),
        );

        $nodes = $subgraph->findDescendantNodes($entryNodeId, $filter);

        return $this->serializeNodes($nodes, $this->propertyTruncateLength);
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function getNode(string $nodeAggregateId, ?array $dimensionSpacePoint = null, bool $includeRemoved = false): ?NodeInfo
    {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);
        $node = $subgraph->findNodeById(NodeAggregateId::fromString($nodeAggregateId));

        if ($node === null) {
            return null;
        }

        return $this->serializeNode($node);
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function getChildren(
        string $parentNodeAggregateId,
        ?string $nodeTypeName = null,
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): NodeInfoCollection {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);

        $filter = FindChildNodesFilter::create(
            nodeTypes: $nodeTypeName,
        );

        $nodes = $subgraph->findChildNodes(
            NodeAggregateId::fromString($parentNodeAggregateId),
            $filter,
        );

        return $this->serializeNodes($nodes, $this->propertyTruncateLength);
    }

    /**
     * Outgoing references — which nodes does the source node point at? Reference
     * properties (when the reference type declares them) are surfaced alongside
     * each target. Missing source nodes yield an empty collection rather than an
     * error, matching how {@see findNodes()} handles a missing entry point.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function findReferences(
        string $nodeAggregateId,
        ?string $referenceName = null,
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ReferenceInfoCollection {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);

        $references = $subgraph->findReferences(
            NodeAggregateId::fromString($nodeAggregateId),
            FindReferencesFilter::create(referenceName: $referenceName),
        );

        return $this->serializeReferences($references);
    }

    /**
     * Incoming references — which nodes point at the target node? Useful for
     * impact analysis before deletes or moves.
     *
     * @param array<string, string>|null $dimensionSpacePoint
     */
    public function findBackReferences(
        string $nodeAggregateId,
        ?string $referenceName = null,
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): ReferenceInfoCollection {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);

        $references = $subgraph->findBackReferences(
            NodeAggregateId::fromString($nodeAggregateId),
            FindBackReferencesFilter::create(referenceName: $referenceName),
        );

        return $this->serializeReferences($references);
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     */
    private function getSubgraph(?array $dimensionSpacePoint = null, bool $includeRemoved = false): ContentSubgraphInterface
    {
        $dsp = $dimensionSpacePoint !== null
            ? DimensionSpacePoint::fromArray($dimensionSpacePoint)
            : $this->resolveDefaultDimensionSpacePoint();

        $visibilityConstraints = $includeRemoved
            ? VisibilityConstraints::createEmpty()
            : NeosVisibilityConstraints::excludeRemoved();

        return $this->contentRepository->getContentGraph($this->workspaceName)
            ->getSubgraph($dsp, $visibilityConstraints);
    }

    private function resolveDefaultDimensionSpacePoint(): DimensionSpacePoint
    {
        foreach ($this->contentRepository->getDimensionSpacePoints() as $point) {
            return $point;
        }

        return DimensionSpacePoint::createWithoutDimensions();
    }

    private function findSitesRootNodeId(ContentSubgraphInterface $subgraph): ?NodeAggregateId
    {
        $rootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));

        return $rootNode?->aggregateId;
    }

    private function serializeReferences(References $references): ReferenceInfoCollection
    {
        $items = [];
        foreach ($references as $reference) {
            $items[] = $this->serializeReference($reference);
        }

        return ReferenceInfoCollection::create(...$items);
    }

    private function serializeReference(Reference $reference): ReferenceInfo
    {
        $properties = [];
        if ($reference->properties !== null) {
            foreach ($reference->properties as $propertyName => $propertyValue) {
                $properties[$propertyName] = $this->serializePropertyValue($propertyValue, $this->propertyTruncateLength);
            }
        }

        return new ReferenceInfo(
            referenceName: $reference->name->value,
            target: $this->serializeNode($reference->node, $this->propertyTruncateLength),
            properties: $properties,
        );
    }

    /**
     * @param iterable<Node> $nodes
     */
    private function serializeNodes(iterable $nodes, ?int $truncateStringsAt = null): NodeInfoCollection
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = $this->serializeNode($node, $truncateStringsAt);
        }

        return new NodeInfoCollection(...$result);
    }

    private function serializeNode(Node $node, ?int $truncateStringsAt = null): NodeInfo
    {
        $properties = [];
        foreach ($node->properties as $propertyName => $propertyValue) {
            $properties[$propertyName] = $this->serializePropertyValue($propertyValue, $truncateStringsAt);
        }

        return new NodeInfo(
            nodeAggregateId: $node->aggregateId->value,
            nodeTypeName: $node->nodeTypeName->value,
            nodeName: $node->name?->value,
            hidden: $node->tags->contain(NeosSubtreeTag::disabled()),
            properties: $properties,
        );
    }

    private function serializePropertyValue(mixed $value, ?int $truncateStringsAt = null): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::truncateString($value, $truncateStringsAt);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof \Stringable) {
            $stringValue = (string) $value;

            return self::truncateString($stringValue, $truncateStringsAt);
        }

        if (is_object($value)) {
            return sprintf('[object:%s]', $value::class);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->serializePropertyValue($v, $truncateStringsAt), $value);
        }

        return null;
    }

    private static function truncateString(string $value, ?int $maxLength): string
    {
        if ($maxLength === null || mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '…';
    }
}
