<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class NodeTypeService
{
    public function __construct(
        private ContentRepositoryFacade $contentRepository,
    ) {
    }

    /**
     * @return array<int, array{name: string, label: string, abstract: bool, final: bool, superTypes: list<string>, declaredProperties: list<string>}>
     */
    public function listNodeTypes(?string $filter = null): array
    {
        $nodeTypeManager = $this->contentRepository->getNodeTypeManager();
        $nodeTypes = $nodeTypeManager->getNodeTypes(includeAbstractNodeTypes: false);

        $result = [];
        foreach ($nodeTypes as $nodeType) {
            if ($filter !== null && !str_contains(strtolower($nodeType->name->value), strtolower($filter))) {
                continue;
            }

            $properties = array_keys($nodeType->getProperties());

            $superTypeNames = [];
            foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
                $superTypeNames[] = $superType->name->value;
            }

            $result[] = [
                'name' => $nodeType->name->value,
                'label' => $nodeType->getLabel(),
                'abstract' => $nodeType->isAbstract(),
                'final' => $nodeType->isFinal(),
                'superTypes' => $superTypeNames,
                'declaredProperties' => $properties,
            ];
        }

        return $result;
    }

    /**
     * @return array{name: string, label: string, abstract: bool, final: bool, superTypes: list<string>, properties: array<string, array{type: string, defaultValue: mixed}>, childNodes: array<string, string>, references: array<string, mixed>}
     */
    public function getNodeTypeSchema(string $nodeTypeName): array
    {
        $nodeTypeManager = $this->contentRepository->getNodeTypeManager();
        $nodeType = $nodeTypeManager->getNodeType(NodeTypeName::fromString($nodeTypeName));

        if ($nodeType === null) {
            throw new \InvalidArgumentException(sprintf('Node type "%s" not found.', $nodeTypeName), 1738000001);
        }

        $superTypeNames = [];
        foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
            $superTypeNames[] = $superType->name->value;
        }

        $properties = [];
        foreach ($nodeType->getProperties() as $propertyName => $propertyConfig) {
            if (!is_string($propertyName)) {
                continue;
            }
            $configArray = is_array($propertyConfig) ? $propertyConfig : [];
            $type = isset($configArray['type']) && is_string($configArray['type']) ? $configArray['type'] : 'string';
            $properties[$propertyName] = [
                'type' => $type,
                'defaultValue' => $configArray['defaultValue'] ?? null,
            ];
        }

        $childNodes = [];
        foreach ($nodeType->tetheredNodeTypeDefinitions as $tetheredNodeTypeDefinition) {
            $childNodes[$tetheredNodeTypeDefinition->name->value] = $tetheredNodeTypeDefinition->nodeTypeName->value;
        }

        return [
            'name' => $nodeType->name->value,
            'label' => $nodeType->getLabel(),
            'abstract' => $nodeType->isAbstract(),
            'final' => $nodeType->isFinal(),
            'superTypes' => $superTypeNames,
            'properties' => $properties,
            'childNodes' => $childNodes,
            'references' => $nodeType->getReferences(),
        ];
    }
}
