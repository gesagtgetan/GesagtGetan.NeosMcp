<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Tool;

use GesagtGetan\NeosMcp\Tool\McpNodeToolProvider;
use GesagtGetan\NeosMcp\Tool\McpToolReflector;
use GesagtGetan\NeosMcp\Tool\McpWorkspaceToolProvider;
use Neos\Flow\Tests\UnitTestCase;
use PhpMcp\Server\Server;

class McpToolReflectorTest extends UnitTestCase
{
    /**
     * @test
     */
    public function forwardsAttributeDescriptionsAndAnnotationsFromNodeProvider(): void
    {
        $builder = Server::make()->withServerInfo('test', '0.0.0');
        $builder = McpToolReflector::register($builder, McpNodeToolProvider::class);
        $tools = $builder->build()->getRegistry()->getTools();

        // createNode has an explicit description in #[McpTool(description: ...)]
        self::assertArrayHasKey('createNode', $tools);
        self::assertNotNull($tools['createNode']->description, 'createNode description must be forwarded from attribute');
        self::assertStringContainsString('Create a new node', $tools['createNode']->description);

        // getContentRepositoryInfo has readOnlyHint: true in #[McpTool(annotations: ...)]
        self::assertArrayHasKey('getContentRepositoryInfo', $tools);
        self::assertNotNull($tools['getContentRepositoryInfo']->annotations, 'annotations must be forwarded from attribute');
        self::assertTrue($tools['getContentRepositoryInfo']->annotations->readOnlyHint);

        // removeNode has destructiveHint: true
        self::assertArrayHasKey('removeNode', $tools);
        self::assertNotNull($tools['removeNode']->annotations);
        self::assertTrue($tools['removeNode']->annotations->destructiveHint);
    }

    /**
     * @test
     */
    public function forwardsAttributesFromWorkspaceProvider(): void
    {
        $builder = Server::make()->withServerInfo('test', '0.0.0');
        $builder = McpToolReflector::register($builder, McpWorkspaceToolProvider::class);
        $tools = $builder->build()->getRegistry()->getTools();

        self::assertArrayHasKey('getWorkspaceStatus', $tools);
        self::assertTrue($tools['getWorkspaceStatus']->annotations?->readOnlyHint);

        self::assertArrayHasKey('discardWorkspaceChanges', $tools);
        self::assertTrue($tools['discardWorkspaceChanges']->annotations?->destructiveHint);
    }
}
