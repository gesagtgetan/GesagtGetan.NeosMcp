<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Service\NodeTypeService;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

class NodeTypeServiceTest extends UnitTestCase
{
    private NodeTypeService $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);
        $this->subject = new NodeTypeService($this->contentRepository);
    }

    #[Test]
    public function listNodeTypesReturnsOnlyNonAbstractTypes(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => [
                'abstract' => false,
                'properties' => ['title' => ['type' => 'string']],
            ],
            'Vendor:Mixin.Abstract' => [
                'abstract' => true,
                'properties' => ['foo' => ['type' => 'string']],
            ],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->listNodeTypes();

        // NodeTypeManager always includes the built-in Neos.ContentRepository:Root type
        $names = array_map(static fn ($summary) => $summary->name, iterator_to_array($result));
        self::assertContains('Vendor:Document.Page', $names);
        self::assertNotContains('Vendor:Mixin.Abstract', $names);
    }

    #[Test]
    public function listNodeTypesFiltersByNameCaseInsensitive(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => ['properties' => ['title' => ['type' => 'string']]],
            'Vendor:Content.Text' => ['properties' => ['text' => ['type' => 'string']]],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->listNodeTypes('document');

        self::assertCount(1, $result);
        $summary = iterator_to_array($result)[0];
        self::assertSame('Vendor:Document.Page', $summary->name);
    }

    #[Test]
    public function listNodeTypesReturnsExpectedStructure(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => [
                'properties' => [
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->listNodeTypes('Vendor:Document');

        self::assertCount(1, $result);
        $summary = iterator_to_array($result)[0];
        self::assertSame('Vendor:Document.Page', $summary->name);
        self::assertFalse($summary->abstract);
        self::assertContains('title', $summary->declaredProperties);
        self::assertContains('body', $summary->declaredProperties);
    }

    #[Test]
    public function getNodeTypeSchemaReturnsFullSchema(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => [
                'properties' => [
                    'title' => ['type' => 'string', 'defaultValue' => 'Untitled'],
                ],
            ],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->getNodeTypeSchema('Vendor:Document.Page');

        self::assertSame('Vendor:Document.Page', $result->name);
        self::assertTrue($result->properties->has('title'));
        $titleDefinition = $result->properties->get('title');
        self::assertNotNull($titleDefinition);
        self::assertSame('string', $titleDefinition->type);
        self::assertSame('Untitled', $titleDefinition->defaultValue);
    }

    #[Test]
    public function getNodeTypeSchemaIncludesPropertyUiLabelAndDescription(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => [
                'properties' => [
                    'titleOverride' => [
                        'type' => 'string',
                        'ui' => [
                            'label' => 'SEO title',
                            'help' => ['message' => 'Used for the browser tab and search results. Leave empty to fall back to the regular title.'],
                        ],
                    ],
                    'noHelp' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->getNodeTypeSchema('Vendor:Document.Page');

        $titleOverride = $result->properties->get('titleOverride');
        self::assertNotNull($titleOverride);
        self::assertSame('SEO title', $titleOverride->label);
        self::assertNotNull($titleOverride->description);
        self::assertStringContainsString('Used for the browser tab', $titleOverride->description);

        $noHelp = $result->properties->get('noHelp');
        self::assertNotNull($noHelp);
        self::assertNull($noHelp->label);
        self::assertNull($noHelp->description);
    }

    #[Test]
    public function getNodeTypeSchemaIncludesPropertyValidation(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([
            'Vendor:Document.Page' => [
                'properties' => [
                    'titleOverride' => [
                        'type' => 'string',
                        'validation' => [
                            'Neos.Neos/Validation/StringLengthValidator' => ['maximum' => 60],
                            'Neos.Neos/Validation/NotEmptyValidator' => [],
                        ],
                    ],
                    'price' => [
                        'type' => 'integer',
                        'validation' => [
                            'Neos.Neos/Validation/NumberRangeValidator' => ['minimum' => 0, 'maximum' => 999],
                        ],
                    ],
                    'noValidation' => ['type' => 'string'],
                    'wholeBlockDisabled' => ['type' => 'string', 'validation' => null],
                    'singleValidatorDisabled' => [
                        'type' => 'string',
                        'validation' => [
                            'Neos.Neos/Validation/StringLengthValidator' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $result = $this->subject->getNodeTypeSchema('Vendor:Document.Page');

        $titleOverride = $result->properties->get('titleOverride');
        self::assertNotNull($titleOverride);
        self::assertSame(
            ['StringLength' => ['maximum' => 60], 'NotEmpty' => []],
            $titleOverride->validation,
        );

        $price = $result->properties->get('price');
        self::assertNotNull($price);
        self::assertSame(
            ['NumberRange' => ['minimum' => 0, 'maximum' => 999]],
            $price->validation,
        );

        self::assertNull($result->properties->get('noValidation')?->validation);
        self::assertNull($result->properties->get('wholeBlockDisabled')?->validation);
        self::assertNull($result->properties->get('singleValidatorDisabled')?->validation);
    }

    #[Test]
    public function getNodeTypeSchemaThrowsForUnknownType(): void
    {
        $nodeTypeManager = $this->createNodeTypeManagerWithTypes([]);
        $this->contentRepository->method('getNodeTypeManager')->willReturn($nodeTypeManager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1738000001);

        $this->subject->getNodeTypeSchema('Vendor:NonExistent');
    }

    /**
     * @param array<string, array<string, mixed>> $typeConfigurations
     */
    private function createNodeTypeManagerWithTypes(array $typeConfigurations): NodeTypeManager
    {
        return NodeTypeManager::createFromArrayConfiguration($typeConfigurations);
    }
}
