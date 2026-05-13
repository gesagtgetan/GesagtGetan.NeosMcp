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
     * @return array{name: string, label: string, abstract: bool, final: bool, superTypes: list<string>, properties: array<string, array{type: string, defaultValue: mixed, label?: string, description?: string, validation?: array<string, mixed>}>, childNodes: array<string, string>, references: array<string, mixed>}
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
            $entry = [
                'type' => $type,
                'defaultValue' => $configArray['defaultValue'] ?? null,
            ];

            // Surface property-level UI hints from NodeTypes.yaml so the LLM can pick the
            // right property without trial and error. `ui.label` is the field label the
            // Neos editor renders above each input; `ui.help.message` is the tooltip
            // shown on hover. Reusing them means existing content-author guidance flows
            // through to the LLM unchanged.
            $uiConfig = is_array($configArray['ui'] ?? null) ? $configArray['ui'] : [];
            if (isset($uiConfig['label']) && is_string($uiConfig['label']) && $uiConfig['label'] !== '') {
                $entry['label'] = $uiConfig['label'];
            }
            $help = $uiConfig['help'] ?? null;
            if (is_array($help) && isset($help['message']) && is_string($help['message']) && $help['message'] !== '') {
                $entry['description'] = $help['message'];
            }

            // Pass validator declarations through 1:1, shortening the validator name to its
            // bare form (`Neos.Neos/Validation/StringLengthValidator` → `StringLength`) so
            // the LLM sees a familiar shape it can interpret without an explicit mapping
            // table on our side. The output key mirrors the source key `validation:` from
            // NodeTypes.yaml so devs and the LLM read the same vocabulary. Custom
            // validators from third-party packages survive the shortening too.
            $validation = [];
            $validatorConfig = is_array($configArray['validation'] ?? null) ? $configArray['validation'] : [];
            foreach ($validatorConfig as $validatorName => $options) {
                if (!is_string($validatorName) || !is_array($options)) {
                    // Null / false / scalar options mean the validator is disabled (the
                    // standard NodeTypes.yaml convention for switching a validator off in
                    // an override). Skip it instead of misreporting it as enabled.
                    continue;
                }
                $shortName = str_contains($validatorName, '/')
                    ? substr($validatorName, (int) strrpos($validatorName, '/') + 1)
                    : $validatorName;
                if (str_ends_with($shortName, 'Validator')) {
                    $shortName = substr($shortName, 0, -strlen('Validator'));
                }
                $validation[$shortName] = $options;
            }
            if ($validation !== []) {
                $entry['validation'] = $validation;
            }

            $properties[$propertyName] = $entry;
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
