<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Controller;

use Composer\InstalledVersions;
use GesagtGetan\NeosMcp\DefaultContentRepositoryFacade;
use GesagtGetan\NeosMcp\McpToolProvider;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
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
    protected RedirectStorageInterface $redirectStorage;

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    #[Flow\InjectConfiguration(path: 'contentRepositoryId', package: 'GesagtGetan.NeosMcp')]
    protected string $contentRepositoryId;

    #[Flow\InjectConfiguration(path: 'workspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $workspaceName;

    public function preflightAction(): ResponseInterface
    {
        return new Response(204, $this->corsHeaders());
    }

    public function handleAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $httpRequest = $this->request->getHttpRequest();
        $body = (string) $httpRequest->getBody();

        // Validate JWT bearer token via league's ResourceServer.
        $psrRequest = new ServerRequest(
            method: 'POST',
            uri: (string) $httpRequest->getUri(),
            headers: $httpRequest->getHeaders(),
            body: $body,
            serverParams: $_SERVER,
        );

        $resourceServer = $this->oauthServerFactory->createResourceServer();

        try {
            $resourceServer->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException) {
            return $this->unauthorizedResponse();
        }

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

        $result = $this->securityContext->withoutAuthorizationChecks(function () use ($message): JsonRpcResponse|JsonRpcError {
            return $this->dispatch($message);
        });

        return $this->jsonResponse(200, $result->toArray());
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

    private function dispatch(Request $request): JsonRpcResponse|JsonRpcError
    {
        $server = $this->buildServer();
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

    protected function buildServer(): Server
    {
        $crId = ContentRepositoryId::fromString($this->contentRepositoryId);
        $contentRepository = $this->contentRepositoryRegistry->get($crId);
        $workspaceName = WorkspaceName::fromString($this->workspaceName);

        $facade = new DefaultContentRepositoryFacade($contentRepository);
        $toolProvider = new McpToolProvider($facade, $workspaceName, $this->redirectStorage);

        $container = new BasicContainer();
        $container->set(McpToolProvider::class, $toolProvider);

        $builder = Server::make()
            ->withContainer($container)
            ->withServerInfo('GesagtGetan.NeosMcp', InstalledVersions::getPrettyVersion('gesagtgetan/neos-mcp') ?? 'dev');

        $builder = McpToolProvider::registerTools($builder);

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
