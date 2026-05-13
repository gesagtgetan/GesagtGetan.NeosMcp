<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\DiscardWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use PhpMcp\Schema\ToolAnnotations;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\ServerBuilder;

/**
 * Built-in MCP tool provider for workspace-level operations (status, discard).
 *
 * Same lifecycle model as {@see McpNodeToolProvider}: prototype-scoped Flow
 * service whose request context is set once by {@see registerTools()} on a
 * fresh instance per MCP request. Held alongside the node-tools provider so
 * the class names match the kind of operation each performs.
 */
#[Flow\Scope('prototype')]
final class McpWorkspaceToolProvider implements McpToolProvider
{
    private ContentRepositoryFacade $contentRepository;
    private WorkspaceName $workspaceName;
    private WorkspaceRebaser $rebaser;

    public function registerTools(
        ServerBuilder $builder,
        BasicContainer $container,
        McpRequestContext $context,
    ): ServerBuilder {
        $this->contentRepository = $context->contentRepository;
        $this->workspaceName = $context->workspaceName;
        $this->rebaser = new WorkspaceRebaser($context->contentRepository, $context->workspaceName);

        $container->set(self::class, $this);

        return McpToolReflector::register($builder, self::class);
    }

    /**
     * Show the workspace status including pending change count.
     *
     * @return array{workspaceName: string, baseWorkspace: ?string, status: string, hasPendingChanges: bool}
     */
    #[McpTool(annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getWorkspaceStatus(): array
    {
        $this->rebaser->rebase();
        $workspace = $this->contentRepository->findWorkspaceByName($this->workspaceName);
        if ($workspace === null) {
            return [
                'workspaceName' => $this->workspaceName->value,
                'baseWorkspace' => null,
                'status' => 'not_found',
                'hasPendingChanges' => false,
            ];
        }

        return [
            'workspaceName' => $workspace->workspaceName->value,
            'baseWorkspace' => $workspace->baseWorkspaceName?->value,
            'status' => $workspace->status->value,
            'hasPendingChanges' => $workspace->hasPublishableChanges(),
        ];
    }

    /**
     * Discard all pending changes in the workspace.
     *
     * @return array{workspaceName: string, success: true}
     */
    #[McpTool(annotations: new ToolAnnotations(destructiveHint: true))]
    public function discardWorkspaceChanges(): array
    {
        $this->rebaser->rebase();
        $this->contentRepository->handle(DiscardWorkspace::create($this->workspaceName));

        return [
            'workspaceName' => $this->workspaceName->value,
            'success' => true,
        ];
    }
}
