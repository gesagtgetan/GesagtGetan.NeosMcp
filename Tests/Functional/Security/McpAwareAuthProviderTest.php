<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\Security;

use GesagtGetan\NeosMcp\Security\McpUserContext;
use GesagtGetan\NeosMcp\Service\NodeWriteService;
use GesagtGetan\NeosMcp\Tests\Functional\AbstractFunctionalTest;
use Neos\ContentRepository\Core\EventStore\InitiatingEventMetadata;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

class McpAwareAuthProviderTest extends AbstractFunctionalTest
{
    private McpUserContext $mcpUserContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mcpUserContext = $this->objectManager->get(McpUserContext::class);
        $this->mcpUserContext->clear();
    }

    protected function tearDown(): void
    {
        $this->mcpUserContext->clear();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function crEventRecordsMcpUserAsInitiatingUserId(): void
    {
        $expectedUserId = 'mcp-test-user-' . bin2hex(random_bytes(4));
        $this->mcpUserContext->setUserId(UserId::fromString($expectedUserId));

        $workspaceName = WorkspaceName::fromString('test-workspace');
        $this->createTestWorkspace($workspaceName);

        $writeService = new NodeWriteService($this->facade, $workspaceName);
        $writeService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Created by MCP user'],
        );

        $eventStore = $this->getEventStore();
        $lastInitiatingUserId = null;

        foreach ($eventStore->load(VirtualStreamName::all()) as $envelope) {
            $metadata = $envelope->event->metadata;
            if ($metadata !== null && $metadata->has(InitiatingEventMetadata::INITIATING_USER_ID)) {
                $lastInitiatingUserId = $metadata->get(InitiatingEventMetadata::INITIATING_USER_ID);
            }
        }

        self::assertSame($expectedUserId, $lastInitiatingUserId);
    }

    /**
     * @test
     */
    public function crEventRecordsSystemUserWhenNoMcpUser(): void
    {
        // McpUserContext is cleared in setUp — no MCP user active
        $workspaceName = WorkspaceName::fromString('test-workspace');
        $this->createTestWorkspace($workspaceName);

        $writeService = new NodeWriteService($this->facade, $workspaceName);
        $writeService->createNode(
            self::$siteNodeId->value,
            'GesagtGetan.NeosMcp:Testing.Document',
            ['title' => 'Created without MCP user'],
        );

        $eventStore = $this->getEventStore();
        $lastInitiatingUserId = null;

        foreach ($eventStore->load(VirtualStreamName::all()) as $envelope) {
            $metadata = $envelope->event->metadata;
            if ($metadata !== null && $metadata->has(InitiatingEventMetadata::INITIATING_USER_ID)) {
                $lastInitiatingUserId = $metadata->get(InitiatingEventMetadata::INITIATING_USER_ID);
            }
        }

        // Without MCP user and without Flow SecurityContext session, the inner
        // Neos auth provider returns "system" as the initiating user.
        self::assertSame('system', $lastInitiatingUserId);
    }

    private function getEventStore(): EventStoreInterface
    {
        $registry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $crId = ContentRepositoryId::fromString('default');

        /** @var EventStoreAccessor $accessor */
        $accessor = $registry->buildService($crId, new EventStoreAccessorFactory());

        return $accessor->eventStore;
    }
}
