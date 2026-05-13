<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Tool\McpRequestContext;
use GesagtGetan\NeosMcp\Tool\McpWorkspaceToolProvider;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus;
use Neos\Flow\Tests\UnitTestCase;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PHPUnit\Framework\MockObject\MockObject;

class McpWorkspaceToolProviderTest extends UnitTestCase
{
    private McpWorkspaceToolProvider $subject;
    private ContentRepositoryFacade&MockObject $contentRepository;

    /** @var list<object> */
    private array $handledCommands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);
        $this->handledCommands = [];

        $this->contentRepository->method('handle')->willReturnCallback(
            function (object $command): void {
                if ($command instanceof RebaseWorkspace) {
                    throw new WorkspaceCommandSkipped();
                }
                $this->handledCommands[] = $command;
            },
        );

        $this->subject = new McpWorkspaceToolProvider();
        $this->subject->registerTools(
            Server::make()->withServerInfo('test', '0.0.0'),
            new BasicContainer(),
            new McpRequestContext($this->contentRepository, WorkspaceName::fromString('test-workspace')),
        );
    }

    /**
     * @test
     */
    public function getWorkspaceStatusReturnsNotFoundForMissingWorkspace(): void
    {
        $this->contentRepository->method('findWorkspaceByName')->willReturn(null);

        $result = $this->subject->getWorkspaceStatus();

        self::assertSame('test-workspace', $result['workspaceName']);
        self::assertSame('not_found', $result['status']);
        self::assertFalse($result['hasPendingChanges']);
    }

    /**
     * @test
     */
    public function getWorkspaceStatusReturnsWorkspaceInfo(): void
    {
        $workspace = Workspace::create(
            WorkspaceName::fromString('test-workspace'),
            WorkspaceName::fromString('live'),
            ContentStreamId::fromString('cs-1'),
            WorkspaceStatus::UP_TO_DATE,
            true,
        );
        $this->contentRepository->method('findWorkspaceByName')->willReturn($workspace);

        $result = $this->subject->getWorkspaceStatus();

        self::assertSame('test-workspace', $result['workspaceName']);
        self::assertSame('live', $result['baseWorkspace']);
        self::assertSame('UP_TO_DATE', $result['status']);
        self::assertTrue($result['hasPendingChanges']);
    }

    /**
     * @test
     */
    public function discardWorkspaceChangesCallsHandle(): void
    {
        $this->handledCommands = [];

        $this->subject->discardWorkspaceChanges();

        self::assertCount(1, $this->handledCommands);
        self::assertInstanceOf(DiscardWorkspace::class, $this->handledCommands[0]);
    }
}
