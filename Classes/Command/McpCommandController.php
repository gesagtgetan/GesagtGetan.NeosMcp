<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Command;

use GesagtGetan\NeosMcp\DefaultContentRepositoryFacade;
use GesagtGetan\NeosMcp\McpToolProvider;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;

#[Flow\Scope('singleton')]
class McpCommandController extends CommandController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected RedirectStorageInterface $redirectStorage;

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    #[Flow\InjectConfiguration(path: 'contentRepositoryId', package: 'GesagtGetan.NeosMcp')]
    protected string $contentRepositoryId;

    #[Flow\InjectConfiguration(path: 'workspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $workspaceName;

    #[Flow\InjectConfiguration(path: 'workspaceBaseWorkspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $workspaceBaseWorkspaceName;

    /**
     * Set up the MCP review workspace with proper Neos metadata.
     *
     * Creates a shared workspace visible in the Neos UI. Run once during setup.
     * Idempotent — skips if the workspace already exists.
     */
    public function setupCommand(): void
    {
        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($crId);
        $workspaceName = WorkspaceName::fromString($this->workspaceName);

        if ($contentRepository->findWorkspaceByName($workspaceName) !== null) {
            $this->outputLine('Workspace "%s" already exists.', [$workspaceName->value]);

            return;
        }

        $this->workspaceService->createSharedWorkspace(
            $crId,
            $workspaceName,
            new WorkspaceTitle('LLM Review'),
            new WorkspaceDescription('Review workspace for MCP-generated content changes'),
            WorkspaceName::fromString($this->workspaceBaseWorkspaceName),
            WorkspaceRoleAssignments::create(
                WorkspaceRoleAssignment::createForGroup(
                    'Neos.Neos:AbstractEditor',
                    WorkspaceRole::COLLABORATOR,
                ),
            ),
        );

        $this->outputLine('Created shared workspace "%s".', [$workspaceName->value]);

        $this->ensureOAuthClient();
    }

    private function ensureOAuthClient(): void
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return;
        }

        $this->oauthServerFactory->ensureClient();
        $this->outputLine('OAuth client ensured.');
    }

    /**
     * Start the MCP server (stdio transport).
     *
     * Provides LLMs with structured access to the Neos Content Repository.
     * All write operations target the configured review workspace.
     */
    public function serverCommand(): void
    {
        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($crId);
        $workspaceName = WorkspaceName::fromString($this->workspaceName);

        if ($contentRepository->findWorkspaceByName($workspaceName) === null) {
            $this->outputLine('ERROR: Workspace "%s" does not exist. Run ./flow mcp:setup first.', [$workspaceName->value]);
            $this->quit(1);
        }

        $facade = new DefaultContentRepositoryFacade($contentRepository);
        $toolProvider = new McpToolProvider($facade, $workspaceName, $this->redirectStorage);

        $container = new BasicContainer();
        $container->set(McpToolProvider::class, $toolProvider);

        // Register all #[McpTool]-annotated methods from the provider.
        // Tool names, descriptions, and annotations live on the methods themselves,
        // so adding a new tool only requires adding a method — no registration here.
        $builder = Server::make()
            ->withContainer($container)
            ->withServerInfo('GesagtGetan.NeosMcp', '1.0.0');

        foreach ((new \ReflectionClass(McpToolProvider::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(McpTool::class) !== []) {
                $builder = $builder->withTool([McpToolProvider::class, $method->getName()]);
            }
        }

        $server = $builder->build();

        $transport = new StdioServerTransport();

        // Flow's security context requires an HTTP session with an authenticated user,
        // which doesn't exist in CLI mode. Disabling authorization checks for the entire
        // ReactPHP event loop allows all MCP tool invocations to access the CR freely.
        $this->securityContext->withoutAuthorizationChecks(function () use ($server, $transport): void {
            $server->listen($transport);
        });
    }
}
