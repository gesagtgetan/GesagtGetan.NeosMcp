<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Security;

use GesagtGetan\NeosMcp\Security\McpAwareAuthProvider;
use GesagtGetan\NeosMcp\Security\McpUserContext;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Feature\Security\Dto\Privilege;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

class McpAwareAuthProviderTest extends UnitTestCase
{
    private AuthProviderInterface&MockObject $inner;
    private McpUserContext $mcpUserContext;
    private McpAwareAuthProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = $this->createMock(AuthProviderInterface::class);
        $this->mcpUserContext = new McpUserContext();
        $this->subject = new McpAwareAuthProvider($this->inner, $this->mcpUserContext);
    }

    #[Test]
    public function mcpUserTakesPrecedenceOverInnerProvider(): void
    {
        $mcpUserId = UserId::fromString('mcp-user-uuid');
        $innerUserId = UserId::fromString('inner-user-uuid');

        $this->inner->method('getAuthenticatedUserId')->willReturn($innerUserId);
        $this->mcpUserContext->setUserId($mcpUserId);

        $result = $this->subject->getAuthenticatedUserId();
        self::assertNotNull($result);
        self::assertTrue($mcpUserId->equals($result));
    }

    #[Test]
    public function fallsBackToInnerProviderWhenNoMcpUser(): void
    {
        $innerUserId = UserId::fromString('inner-user-uuid');
        $this->inner->method('getAuthenticatedUserId')->willReturn($innerUserId);

        $result = $this->subject->getAuthenticatedUserId();
        self::assertNotNull($result);
        self::assertTrue($innerUserId->equals($result));
    }

    #[Test]
    public function returnsNullWhenNeitherMcpNorInnerHasUser(): void
    {
        $this->inner->method('getAuthenticatedUserId')->willReturn(null);

        self::assertNull($this->subject->getAuthenticatedUserId());
    }

    #[Test]
    public function clearRemovesMcpUserAndFallsBackToInner(): void
    {
        $mcpUserId = UserId::fromString('mcp-user-uuid');
        $innerUserId = UserId::fromString('inner-user-uuid');

        $this->inner->method('getAuthenticatedUserId')->willReturn($innerUserId);
        $this->mcpUserContext->setUserId($mcpUserId);

        $result = $this->subject->getAuthenticatedUserId();
        self::assertNotNull($result);
        self::assertTrue($mcpUserId->equals($result));

        $this->mcpUserContext->clear();

        $result = $this->subject->getAuthenticatedUserId();
        self::assertNotNull($result);
        self::assertTrue($innerUserId->equals($result));
    }

    #[Test]
    public function delegatesCanReadNodesFromWorkspace(): void
    {
        $workspaceName = WorkspaceName::fromString('user-test');
        $expected = Privilege::granted('test');
        $this->inner->expects(self::once())->method('canReadNodesFromWorkspace')->with($workspaceName)->willReturn($expected);

        self::assertSame($expected, $this->subject->canReadNodesFromWorkspace($workspaceName));
    }

    #[Test]
    public function delegatesGetVisibilityConstraints(): void
    {
        $workspaceName = WorkspaceName::fromString('user-test');
        $expected = VisibilityConstraints::default();
        $this->inner->expects(self::once())->method('getVisibilityConstraints')->with($workspaceName)->willReturn($expected);

        self::assertSame($expected, $this->subject->getVisibilityConstraints($workspaceName));
    }

    #[Test]
    public function delegatesCanExecuteCommand(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $expected = Privilege::granted('test');
        $this->inner->expects(self::once())->method('canExecuteCommand')->with($command)->willReturn($expected);

        self::assertSame($expected, $this->subject->canExecuteCommand($command));
    }
}
