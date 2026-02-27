<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Security;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Feature\Security\Dto\Privilege;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Decorator that returns the MCP-authenticated user when available, falling back
 * to the inner Neos auth provider for all other cases (backend sessions, CLI).
 */
#[Flow\Proxy(false)]
final readonly class McpAwareAuthProvider implements AuthProviderInterface
{
    public function __construct(
        private AuthProviderInterface $inner,
        private McpUserContext $mcpUserContext,
    ) {
    }

    public function getAuthenticatedUserId(): ?UserId
    {
        return $this->mcpUserContext->getUserId() ?? $this->inner->getAuthenticatedUserId();
    }

    public function canReadNodesFromWorkspace(WorkspaceName $workspaceName): Privilege
    {
        return $this->inner->canReadNodesFromWorkspace($workspaceName);
    }

    public function getVisibilityConstraints(WorkspaceName $workspaceName): VisibilityConstraints
    {
        return $this->inner->getVisibilityConstraints($workspaceName);
    }

    public function canExecuteCommand(CommandInterface $command): Privilege
    {
        return $this->inner->canExecuteCommand($command);
    }
}
