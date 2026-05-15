<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\NodeTypeSchema;
use GesagtGetan\NeosMcp\Dto\NodeTypeSummary;
use GesagtGetan\NeosMcp\Dto\NodeTypeSummaryCollection;
use GesagtGetan\NeosMcp\Dto\PropertyDefinition;
use GesagtGetan\NeosMcp\Dto\PropertyDefinitionCollection;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class NodeTypeService
{
    public function __construct(
        private ContentRepositoryFacade $contentRepository,
    ) {
    }

    public function listNodeTypes(?string $filter = null): NodeTypeSummaryCollection
    {
        $nodeTypeManager = $this->contentRepository->getNodeTypeManager();
        $nodeTypes = $nodeTypeManager->getNodeTypes(includeAbstractNodeTypes: false);

        $summaries = [];
        foreach ($nodeTypes as $nodeType) {
            if ($filter !== null && !str_contains(strtolower($nodeType->name->value), strtolower($filter))) {
                continue;
            }

            $properties = array_keys($nodeType->getProperties());

            $superTypeNames = [];
            foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
                $superTypeNames[] = $superType->name->value;
            }

            $summaries[] = new NodeTypeSummary(
                name: $nodeType->name->value,
                label: $nodeType->getLabel(),
                abstract: $nodeType->isAbstract(),
                final: $nodeType->isFinal(),
                superTypes: $superTypeNames,
                declaredProperties: $properties,
            );
        }

        return new NodeTypeSummaryCollection(...$summaries);
    }

    public function getNodeTypeSchema(string $nodeTypeName): NodeTypeSchema
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

        $propertyDefinitions = [];
        foreach ($nodeType->getProperties() as $propertyName => $propertyConfig) {
            if (!is_string($propertyName)) {
                continue;
            }
            $configArray = is_array($propertyConfig) ? $propertyConfig : [];
            $type = isset($configArray['type']) && is_string($configArray['type']) ? $configArray['type'] : 'string';

            // Surface property-level UI hints from NodeTypes.yaml so the LLM can pick the
            // right property without trial and error. `ui.label` is the field label the
            // Neos editor renders above each input; `ui.help.message` is the tooltip
            // shown on hover. Reusing them means existing content-author guidance flows
            // through to the LLM unchanged.
            $uiConfig = is_array($configArray['ui'] ?? null) ? $configArray['ui'] : [];
            $label = null;
            if (isset($uiConfig['label']) && is_string($uiConfig['label']) && $uiConfig['label'] !== '') {
                $label = $uiConfig['label'];
            }
            $description = null;
            $help = $uiConfig['help'] ?? null;
            if (is_array($help) && isset($help['message']) && is_string($help['message']) && $help['message'] !== '') {
                $description = $help['message'];
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

            $propertyDefinitions[] = new PropertyDefinition(
                name: $propertyName,
                type: $type,
                defaultValue: $configArray['defaultValue'] ?? null,
                label: $label,
                description: $description,
                validation: $validation === [] ? null : $validation,
            );
        }

        $childNodes = [];
        foreach ($nodeType->tetheredNodeTypeDefinitions as $tetheredNodeTypeDefinition) {
            $childNodes[$tetheredNodeTypeDefinition->name->value] = $tetheredNodeTypeDefinition->nodeTypeName->value;
        }

        return new NodeTypeSchema(
            name: $nodeType->name->value,
            label: $nodeType->getLabel(),
            abstract: $nodeType->isAbstract(),
            final: $nodeType->isFinal(),
            superTypes: $superTypeNames,
            properties: new PropertyDefinitionCollection(...$propertyDefinitions),
            childNodes: $childNodes,
            references: $nodeType->getReferences(),
        );
    }
}
