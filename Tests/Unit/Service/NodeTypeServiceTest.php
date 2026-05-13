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
        $names = array_column($result, 'name');
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
        self::assertSame('Vendor:Document.Page', $result[0]['name']);
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
        self::assertArrayHasKey('name', $result[0]);
        self::assertArrayHasKey('label', $result[0]);
        self::assertArrayHasKey('abstract', $result[0]);
        self::assertArrayHasKey('final', $result[0]);
        self::assertArrayHasKey('superTypes', $result[0]);
        self::assertArrayHasKey('declaredProperties', $result[0]);
        self::assertFalse($result[0]['abstract']);
        self::assertContains('title', $result[0]['declaredProperties']);
        self::assertContains('body', $result[0]['declaredProperties']);
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

        self::assertSame('Vendor:Document.Page', $result['name']);
        self::assertArrayHasKey('properties', $result);
        self::assertArrayHasKey('title', $result['properties']);
        self::assertSame('string', $result['properties']['title']['type']);
        self::assertSame('Untitled', $result['properties']['title']['defaultValue']);
        self::assertArrayHasKey('childNodes', $result);
        self::assertArrayHasKey('references', $result);
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

        self::assertSame('SEO title', $result['properties']['titleOverride']['label'] ?? null);
        self::assertStringContainsString('Used for the browser tab', $result['properties']['titleOverride']['description'] ?? '');
        self::assertArrayNotHasKey('label', $result['properties']['noHelp']);
        self::assertArrayNotHasKey('description', $result['properties']['noHelp']);
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
