<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Security;

use Neos\ContentRepository\Core\Factory\AuthProviderFactoryInterface;
use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Security\ContentRepositoryAuthProvider\ContentRepositoryAuthProviderFactory;

/**
 * Wraps the Neos {@see ContentRepositoryAuthProviderFactory} to produce a
 * {@see McpAwareAuthProvider} that attributes CR events to the MCP-authenticated user.
 */
#[Flow\Scope('singleton')]
class McpAwareAuthProviderFactory implements AuthProviderFactoryInterface
{
    #[Flow\Inject]
    protected ContentRepositoryAuthProviderFactory $innerFactory;

    #[Flow\Inject]
    protected McpUserContext $mcpUserContext;

    public function build(ContentRepositoryId $contentRepositoryId, ContentGraphReadModelInterface $contentGraphReadModel): AuthProviderInterface
    {
        return new McpAwareAuthProvider(
            $this->innerFactory->build($contentRepositoryId, $contentGraphReadModel),
            $this->mcpUserContext,
        );
    }
}
