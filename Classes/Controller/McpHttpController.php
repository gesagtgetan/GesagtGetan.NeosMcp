<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Controller;

use Composer\InstalledVersions;
use GesagtGetan\NeosMcp\DefaultContentRepositoryFacade;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GesagtGetan\NeosMcp\Security\McpUserContext;
use GesagtGetan\NeosMcp\Tool\McpRequestContext;
use GesagtGetan\NeosMcp\Tool\McpToolProviderRegistry;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Model\UserId;
use Neos\Neos\Domain\Service\WorkspaceService;
use PhpMcp\Schema\Constants;
use PhpMcp\Schema\JsonRpc\Error as JsonRpcError;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response as JsonRpcResponse;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Server;
use PhpMcp\Server\Session\ArraySessionHandler;
use PhpMcp\Server\Session\Session;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * HTTP transport for the MCP server.
 *
 * Receives JSON-RPC requests via POST, validates JWT bearer token via league's
 * ResourceServer, and dispatches them through the MCP Dispatcher. Stateless
 * per-request — no initialize handshake needed.
 */
class McpHttpController extends ActionController
{
    /** @phpstan-var array<string> */
    protected $supportedMediaTypes = ['application/json', 'text/event-stream']; // @phpstan-ignore property.phpDocType

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    #[Flow\Inject]
    protected McpUserContext $mcpUserContext;

    #[Flow\Inject]
    protected McpToolProviderRegistry $toolProviderRegistry;

    #[Flow\InjectConfiguration(path: 'contentRepositoryId', package: 'GesagtGetan.NeosMcp')]
    protected string $contentRepositoryId;

    #[Flow\InjectConfiguration(path: 'propertyTruncateLength', package: 'GesagtGetan.NeosMcp')]
    protected ?int $propertyTruncateLength;

    /**
     * @var list<string>
     */
    #[Flow\InjectConfiguration(path: 'disabledTools', package: 'GesagtGetan.NeosMcp')]
    protected array $disabledTools = [];

    public function preflightAction(): ResponseInterface
    {
        return new Response(204, $this->corsHeaders());
    }

    public function handleAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(503, ['error' => 'MCP HTTP transport is disabled. Set GesagtGetan.NeosMcp.oauth.enabled to true in Settings.yaml and run ./flow mcp:setup.']);
        }

        $httpRequest = $this->request->getHttpRequest();
        $body = (string) $httpRequest->getBody();

        $resourceServer = $this->oauthServerFactory->createResourceServer();

        try {
            $validatedRequest = $resourceServer->validateAuthenticatedRequest($httpRequest);
        } catch (OAuthServerException) {
            return $this->unauthorizedResponse();
        }

        $result = $this->resolveWorkspaceName($validatedRequest);

        // no workspace could be resolved, return the error response
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $workspaceName = $result;

        $oauthUserId = $validatedRequest->getAttribute('oauth_user_id');
        if (\is_string($oauthUserId)) {
            $this->mcpUserContext->setUserId(
                \Neos\ContentRepository\Core\Feature\Security\Dto\UserId::fromString($oauthUserId),
            );
        }

        try {
            try {
                $message = Parser::parseRequestMessage($body);
            } catch (\JsonException | \InvalidArgumentException) {
                return $this->jsonResponse(400, JsonRpcError::forParseError('Invalid JSON-RPC request')->toArray());
            }

            if ($message instanceof Notification) {
                return new Response(204, $this->corsHeaders());
            }

            if (!$message instanceof Request) {
                return $this->jsonResponse(
                    400,
                    JsonRpcError::forInvalidRequest('Only single JSON-RPC requests are supported', '')->toArray(),
                );
            }

            // OAuth authentication is separate from Flow's session-based security context.
            // The JWT bearer token was validated above and the user identity is stored in
            // McpUserContext, but Flow's SecurityContext has no authenticated session, so its
            // AOP method interceptors would reject CR operations. Bypass is intentional —
            // authorization is handled at the OAuth layer, not by Flow's internal policies.
            $result = $this->securityContext->withoutAuthorizationChecks(function () use ($message, $workspaceName): JsonRpcResponse|JsonRpcError {
                return $this->dispatch($message, $workspaceName);
            });

            return $this->jsonResponse(200, $result->toArray());
        } finally {
            $this->mcpUserContext->clear();
        }
    }

    private function unauthorizedResponse(): ResponseInterface
    {
        $issuer = $this->oauthServerFactory->getIssuer();

        return new Response(
            status: 401,
            headers: [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . $issuer . '/.well-known/oauth-protected-resource"',
            ] + $this->corsHeaders(),
            body: json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR),
        );
    }

    protected function resolveWorkspaceName(ServerRequestInterface $validatedRequest): WorkspaceName|ResponseInterface
    {
        $oauthUserId = $validatedRequest->getAttribute('oauth_user_id');
        if (!\is_string($oauthUserId)) {
            return $this->jsonResponse(403, ['error' => 'OAuth token missing user identity']);
        }

        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);

        try {
            $userId = new UserId($oauthUserId);
        } catch (\InvalidArgumentException) {
            return $this->jsonResponse(403, ['error' => 'Invalid "sub" claim in access token. Re-authorize the application to obtain a new token.']);
        }

        try {
            return $this->workspaceService->getPersonalWorkspaceForUser($crId, $userId)->workspaceName;
        } catch (\RuntimeException) {
            return $this->jsonResponse(403, ['error' => 'No personal workspace found. Log into the Neos backend first to create one.']);
        }
    }

    private function dispatch(Request $request, WorkspaceName $workspaceName): JsonRpcResponse|JsonRpcError
    {
        $server = $this->buildServer($workspaceName);
        $configuration = $server->getConfiguration();
        $registry = $server->getRegistry();

        $subscriptionManager = new SubscriptionManager(new NullLogger());
        $dispatcher = new Dispatcher($configuration, $registry, $subscriptionManager);

        $sessionHandler = new ArraySessionHandler();
        $session = new Session($sessionHandler, 'http-stateless');
        $session->hydrate(['initialized' => true]);

        try {
            $result = $dispatcher->handleRequest($request, $session);

            return JsonRpcResponse::make($request->id, $result);
        } catch (McpServerException $e) {
            return $e->toJsonRpcError($request->id);
        } catch (\Throwable $e) {
            return new JsonRpcError(
                jsonrpc: '2.0',
                id: $request->id,
                code: Constants::INTERNAL_ERROR,
                message: 'Internal error processing method ' . $request->method,
                data: $e->getMessage(),
            );
        }
    }

    protected function buildServer(WorkspaceName $workspaceName): Server
    {
        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($crId);

        $facade = new DefaultContentRepositoryFacade($contentRepository);
        $context = new McpRequestContext($facade, $workspaceName, $this->propertyTruncateLength, $this->disabledTools);

        $container = new BasicContainer();

        $builder = Server::make()
            ->withContainer($container)
            ->withServerInfo('GesagtGetan.NeosMcp', InstalledVersions::getPrettyVersion('gesagtgetan/neos-mcp') ?? 'dev')
            ->withInstructions(McpToolProviderRegistry::INSTRUCTIONS);

        $builder = $this->toolProviderRegistry->registerAll($builder, $container, $context);

        return $builder->build();
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        $origin = $this->request->getHttpRequest()->getHeaderLine('Origin');
        $allowed = $this->oauthServerFactory->getCorsAllowedOrigin($origin);

        if ($allowed === null) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $allowed,
            'Access-Control-Allow-Methods' => 'POST',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
        ];
    }

    /** @param array<mixed> $data */
    private function jsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json'] + $this->corsHeaders(),
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
