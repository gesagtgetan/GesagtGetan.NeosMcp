<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Controller;

use GesagtGetan\NeosMcp\DefaultContentRepositoryFacade;
use GesagtGetan\NeosMcp\McpToolProvider;
use GuzzleHttp\Psr7\Response;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PhpMcp\Schema\Constants;
use PhpMcp\Schema\JsonRpc\Error as JsonRpcError;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response as JsonRpcResponse;
use PhpMcp\Server\Attributes\McpTool;
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
 * Receives JSON-RPC requests via POST, validates bearer token authentication,
 * and dispatches them through the MCP Dispatcher. Stateless per-request —
 * no initialize handshake needed. Each request creates a fresh, pre-initialized session.
 */
class McpHttpController extends ActionController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected RedirectStorageInterface $redirectStorage;

    #[Flow\InjectConfiguration(path: 'contentRepositoryId', package: 'GesagtGetan.NeosMcp')]
    protected string $contentRepositoryId;

    #[Flow\InjectConfiguration(path: 'workspaceName', package: 'GesagtGetan.NeosMcp')]
    protected string $workspaceName;

    /** @var array{enabled?: bool, bearerToken?: string|null} */
    #[Flow\InjectConfiguration(path: 'httpTransport', package: 'GesagtGetan.NeosMcp')]
    protected array $httpTransportSettings;

    public function handleAction(): ResponseInterface
    {
        $bearerToken = $this->httpTransportSettings['bearerToken'] ?? null;

        if (!($this->httpTransportSettings['enabled'] ?? false) || !is_string($bearerToken) || $bearerToken === '') {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $httpRequest = $this->request->getHttpRequest();
        $authHeader = $httpRequest->getHeaderLine('Authorization');

        if (!hash_equals('Bearer ' . $bearerToken, $authHeader)) {
            return $this->jsonResponse(401, ['error' => 'Unauthorized']);
        }

        $body = (string) $httpRequest->getBody();

        try {
            $message = Parser::parseRequestMessage($body);
        } catch (\JsonException | \InvalidArgumentException) {
            return $this->jsonResponse(400, JsonRpcError::forParseError('Invalid JSON-RPC request')->toArray());
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
            ->withServerInfo('GesagtGetan.NeosMcp', '1.0.0');

        foreach ((new \ReflectionClass(McpToolProvider::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(McpTool::class) !== []) {
                $builder = $builder->withTool([McpToolProvider::class, $method->getName()]);
            }
        }

        return $builder->build();
    }

    /** @param array<mixed> $data */
    private function jsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
