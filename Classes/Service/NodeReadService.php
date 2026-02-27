<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Pagination\Pagination;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
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
    ) {
    }

    /**
     * @return array{contentRepositoryId: string, dimensions: array<string, array{values: list<string>}>, workspaces: list<array{name: string, baseWorkspace: ?string, status: string}>, dimensionSpacePoints: list<array<string, string>>}
     */
    public function getContentRepositoryInfo(): array
    {
        $dimensionSource = $this->contentRepository->getContentDimensionSource();
        $dimensions = [];
        foreach ($dimensionSource->getContentDimensionsOrderedByPriority() as $dimension) {
            $values = [];
            foreach ($dimension->values as $dimensionValue) {
                $values[] = $dimensionValue->value;
            }
            $dimensions[$dimension->id->value] = ['values' => $values];
        }

        $dimensionSpacePoints = [];
        foreach ($this->contentRepository->getDimensionSpacePoints() as $dsp) {
            $dimensionSpacePoints[] = $dsp->coordinates;
        }

        $workspaces = [];
        foreach ($this->contentRepository->findWorkspaces() as $workspace) {
            $workspaces[] = [
                'name' => $workspace->workspaceName->value,
                'baseWorkspace' => $workspace->baseWorkspaceName?->value,
                'status' => $workspace->status->value,
            ];
        }

        return [
            'contentRepositoryId' => $this->contentRepository->getId()->value,
            'dimensions' => $dimensions,
            'workspaces' => $workspaces,
            'dimensionSpacePoints' => $dimensionSpacePoints,
        ];
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return list<array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>}>
     */
    public function findNodes(
        ?string $nodeTypeName = null,
        ?string $searchTerm = null,
        ?string $parentNodeAggregateId = null,
        int $limit = 100,
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): array {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);

        $entryNodeId = $parentNodeAggregateId !== null
            ? NodeAggregateId::fromString($parentNodeAggregateId)
            : $this->findSitesRootNodeId($subgraph);

        if ($entryNodeId === null) {
            return [];
        }

        $filter = FindDescendantNodesFilter::create(
            nodeTypes: $nodeTypeName,
            searchTerm: $searchTerm,
            pagination: Pagination::fromLimitAndOffset($limit, 0),
        );

        $nodes = $subgraph->findDescendantNodes($entryNodeId, $filter);

        return $this->serializeNodes($nodes);
    }

    /**
     * @param array<string, string>|null $dimensionSpacePoint
     *
     * @return array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>}|null
     */
    public function getNode(string $nodeAggregateId, ?array $dimensionSpacePoint = null, bool $includeRemoved = false): ?array
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
     *
     * @return list<array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>}>
     */
    public function getChildren(
        string $parentNodeAggregateId,
        ?string $nodeTypeName = null,
        ?array $dimensionSpacePoint = null,
        bool $includeRemoved = false,
    ): array {
        $subgraph = $this->getSubgraph($dimensionSpacePoint, $includeRemoved);

        $filter = FindChildNodesFilter::create(
            nodeTypes: $nodeTypeName,
        );

        $nodes = $subgraph->findChildNodes(
            NodeAggregateId::fromString($parentNodeAggregateId),
            $filter,
        );

        return $this->serializeNodes($nodes);
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

    /**
     * @param iterable<Node> $nodes
     *
     * @return list<array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>}>
     */
    private function serializeNodes(iterable $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = $this->serializeNode($node);
        }

        return $result;
    }

    /**
     * @return array{nodeAggregateId: string, nodeTypeName: string, nodeName: ?string, hidden: bool, properties: array<string, mixed>}
     */
    private function serializeNode(Node $node): array
    {
        $properties = [];
        foreach ($node->properties as $propertyName => $propertyValue) {
            $properties[$propertyName] = $this->serializePropertyValue($propertyValue);
        }

        return [
            'nodeAggregateId' => $node->aggregateId->value,
            'nodeTypeName' => $node->nodeTypeName->value,
            'nodeName' => $node->name?->value,
            'hidden' => $node->tags->contain(NeosSubtreeTag::disabled()),
            'properties' => $properties,
        ];
    }

    private function serializePropertyValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            return sprintf('[object:%s]', $value::class);
        }

        if (is_array($value)) {
            return array_map($this->serializePropertyValue(...), $value);
        }

        return null;
    }
}
