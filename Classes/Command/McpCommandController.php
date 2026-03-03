<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Command;

use Composer\InstalledVersions;
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
    protected OAuthServerFactory $oauthServerFactory;

    #[Flow\InjectConfiguration(path: 'contentRepositoryId', package: 'GesagtGetan.NeosMcp')]
    protected string $contentRepositoryId;

    #[Flow\InjectConfiguration(path: 'stdioWorkspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $stdioWorkspaceName;

    #[Flow\InjectConfiguration(path: 'stdioWorkspaceTitle', package: 'GesagtGetan.NeosMcp')]
    protected string $stdioWorkspaceTitle;

    #[Flow\InjectConfiguration(path: 'stdioWorkspaceDescription', package: 'GesagtGetan.NeosMcp')]
    protected string $stdioWorkspaceDescription;

    #[Flow\InjectConfiguration(path: 'stdioBaseWorkspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $stdioBaseWorkspaceName;

    #[Flow\InjectConfiguration(path: 'propertyTruncateLength', package: 'GesagtGetan.NeosMcp')]
    protected ?int $propertyTruncateLength;

    /**
     * Set up the MCP stdio workspace with proper Neos metadata.
     *
     * Creates a shared workspace visible in the Neos UI. Run once during setup.
     * Idempotent — skips if the workspace already exists.
     */
    public function setupCommand(): void
    {
        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($crId);
        $workspaceName = WorkspaceName::fromString($this->stdioWorkspaceName);

        if ($contentRepository->findWorkspaceByName($workspaceName) !== null) {
            $this->outputLine('Workspace "%s" already exists.', [$workspaceName->value]);
        } else {
            $this->workspaceService->createSharedWorkspace(
                $crId,
                $workspaceName,
                new WorkspaceTitle($this->stdioWorkspaceTitle),
                new WorkspaceDescription($this->stdioWorkspaceDescription),
                WorkspaceName::fromString($this->stdioBaseWorkspaceName),
                WorkspaceRoleAssignments::create(
                    WorkspaceRoleAssignment::createForGroup(
                        'GesagtGetan.NeosMcp:McpUser',
                        WorkspaceRole::COLLABORATOR,
                    ),
                ),
            );

            $this->outputLine('Created shared workspace "%s".', [$workspaceName->value]);
        }

        $this->ensureOAuthClient();
    }

    private function ensureOAuthClient(): void
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return;
        }

        $this->oauthServerFactory->ensureKeys();
        $this->oauthServerFactory->ensureClient();
        $this->outputLine('OAuth client and keys ensured.');
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
        $workspaceName = WorkspaceName::fromString($this->stdioWorkspaceName);

        if ($contentRepository->findWorkspaceByName($workspaceName) === null) {
            $this->outputLine('ERROR: Workspace "%s" does not exist. Run ./flow mcp:setup first.', [$workspaceName->value]);
            $this->quit(1);
        }

        $facade = new DefaultContentRepositoryFacade($contentRepository);
        $toolProvider = new McpToolProvider($facade, $workspaceName, $this->propertyTruncateLength);

        $container = new BasicContainer();
        $container->set(McpToolProvider::class, $toolProvider);

        // Register all #[McpTool]-annotated methods from the provider.
        // Tool names, descriptions, and annotations live on the methods themselves,
        // so adding a new tool only requires adding a method — no registration here.
        $builder = Server::make()
            ->withContainer($container)
            ->withServerInfo('GesagtGetan.NeosMcp', InstalledVersions::getPrettyVersion('gesagtgetan/neos-mcp') ?? 'dev')
            ->withInstructions(McpToolProvider::INSTRUCTIONS);

        $builder = McpToolProvider::registerTools($builder);

        $server = $builder->build();

        $transport = new StdioServerTransport();

        // Flow's security context requires an HTTP session with an authenticated user,
        // which doesn't exist in CLI mode. Disabling authorization checks for the entire
        // event loop allows all MCP tool invocations to access the CR freely.
        $this->securityContext->withoutAuthorizationChecks(function () use ($server, $transport): void {
            $server->listen($transport);
        });
    }
}
