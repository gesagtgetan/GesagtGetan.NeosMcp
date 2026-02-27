<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Controller;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Controller\McpHttpController;
use GesagtGetan\NeosMcp\McpToolProvider;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GesagtGetan\NeosMcp\Security\McpUserContext;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Tests\UnitTestCase;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class McpHttpControllerTest extends UnitTestCase
{
    private McpHttpController $subject;
    private SecurityContext&MockObject $securityContext;
    private OAuthServerFactory&MockObject $oauthServerFactory;
    private ResourceServer&MockObject $resourceServer;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests use a subclass that bypasses workspace resolution (WorkspaceService
        // is final readonly and cannot be mocked). The userNotFoundReturns403 test uses a
        // real McpHttpController to exercise the actual resolveWorkspaceName() path.
        $this->subject = new class extends McpHttpController {
            protected function resolveWorkspaceName(ServerRequestInterface $validatedRequest): WorkspaceName|ResponseInterface // @phpstan-ignore return.unusedType
            {
                return WorkspaceName::fromString('user-test');
            }
        };

        $this->securityContext = $this->createMock(SecurityContext::class);
        $this->securityContext->method('withoutAuthorizationChecks')->willReturnCallback(
            static fn (\Closure $callback): mixed => $callback(),
        );

        $this->oauthServerFactory = $this->createMock(OAuthServerFactory::class);
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);
        $this->oauthServerFactory->method('getIssuer')->willReturn('https://example.com');

        $this->resourceServer = $this->createMock(ResourceServer::class);
        $this->oauthServerFactory->method('createResourceServer')->willReturn($this->resourceServer);

        $this->inject($this->subject, 'securityContext', $this->securityContext);
        $this->inject($this->subject, 'oauthServerFactory', $this->oauthServerFactory);
        $this->inject($this->subject, 'mcpUserContext', new McpUserContext());
    }

    /**
     * @test
     */
    public function disabledEndpointReturns503(): void
    {
        $factory = $this->createMock(OAuthServerFactory::class);
        $factory->method('isEnabled')->willReturn(false);
        $this->inject($this->subject, 'oauthServerFactory', $factory);
        $this->injectRequest('{}', 'Bearer some-jwt');

        $response = $this->subject->handleAction();

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function failedJwtValidationReturns401WithDiscoveryHeader(): void
    {
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willThrowException(OAuthServerException::accessDenied('token validation failed'));
        $this->injectRequest('{}', 'Bearer invalid-jwt');

        $response = $this->subject->handleAction();

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('Unauthorized', $body['error']);
        self::assertSame(
            'Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    /**
     * @test
     */
    public function missingOAuthUserIdReturns403(): void
    {
        // validateAuthenticatedRequest returns the request without oauth_user_id attribute
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willReturnArgument(0);
        $this->injectRequest('{}', 'Bearer valid-jwt');

        $subject = new McpHttpController();
        $this->inject($subject, 'securityContext', $this->securityContext);
        $this->inject($subject, 'oauthServerFactory', $this->oauthServerFactory);
        $this->inject($subject, 'mcpUserContext', new McpUserContext());
        $this->inject($subject, 'contentRepositoryId', 'default');
        $this->injectRequestInto($subject, '{}', 'Bearer valid-jwt');

        $response = $subject->handleAction();

        self::assertSame(403, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('OAuth token missing user identity', $body['error']);
    }

    /**
     * @test
     */
    public function invalidJsonReturns400WithParseError(): void
    {
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willReturnArgument(0);
        $this->injectRequest('not-json', 'Bearer valid-jwt');

        $response = $this->subject->handleAction();

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertIsArray($body['error']);
        self::assertSame(-32700, $body['error']['code']);
    }

    /**
     * @test
     */
    public function notificationReturns204(): void
    {
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willReturnArgument(0);
        $this->injectRequest('{"jsonrpc":"2.0","method":"notifications/initialized"}', 'Bearer valid-jwt');

        $response = $this->subject->handleAction();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * @test
     */
    public function toolsListReturnsJsonRpcResponse(): void
    {
        $this->resourceServer->method('validateAuthenticatedRequest')
            ->willReturnArgument(0);

        $controller = $this->createControllerWithMockServer();
        $this->injectRequestInto(
            $controller,
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
            'Bearer valid-jwt',
        );

        $response = $controller->handleAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(1, $body['id']);
        self::assertIsArray($body['result']);
        self::assertIsArray($body['result']['tools']);
        self::assertNotEmpty($body['result']['tools']);

        /** @var list<array{name: string}> $tools */
        $tools = $body['result']['tools'];
        $toolNames = array_column($tools, 'name');
        self::assertContains('getContentRepositoryInfo', $toolNames);
        self::assertContains('findNodes', $toolNames);
    }

    private function injectRequest(string $body, string $authorizationHeader): void
    {
        $this->injectRequestInto($this->subject, $body, $authorizationHeader);
    }

    private function injectRequestInto(McpHttpController $controller, string $body, string $authorizationHeader): void
    {
        $httpRequest = new ServerRequest('POST', 'http://localhost/api/mcp', [], $body);
        if ($authorizationHeader !== '') {
            $httpRequest = $httpRequest->withHeader('Authorization', $authorizationHeader);
        }

        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);

        $this->inject($controller, 'request', $actionRequest);
    }

    /**
     * Creates a controller subclass with buildServer() and resolveWorkspaceName()
     * overridden to avoid final-class mocking.
     */
    private function createControllerWithMockServer(): McpHttpController
    {
        $facade = $this->createMock(ContentRepositoryFacade::class);
        $facade->method('getDimensionSpacePoints')
            ->willReturn(new DimensionSpacePointSet([DimensionSpacePoint::fromArray(['language' => 'de'])]));
        $facade->method('getNodeTypeManager')
            ->willReturn(NodeTypeManager::createFromArrayConfiguration([]));

        $dimensionSource = $this->createMock(ContentDimensionSourceInterface::class);
        $dimensionSource->method('getContentDimensionsOrderedByPriority')->willReturn([]);
        $facade->method('getContentDimensionSource')->willReturn($dimensionSource);

        $toolProvider = new McpToolProvider($facade, WorkspaceName::fromString('test-workspace'));

        $container = new BasicContainer();
        $container->set(McpToolProvider::class, $toolProvider);

        $builder = Server::make()
            ->withContainer($container)
            ->withServerInfo('GesagtGetan.NeosMcp', '1.0.0');

        $builder = McpToolProvider::registerTools($builder);

        $server = $builder->build();

        $controller = new class ($server) extends McpHttpController {
            public function __construct(private readonly Server $testServer)
            {
            }

            protected function resolveWorkspaceName(ServerRequestInterface $validatedRequest): WorkspaceName|ResponseInterface // @phpstan-ignore return.unusedType
            {
                return WorkspaceName::fromString('user-test');
            }

            protected function buildServer(WorkspaceName $workspaceName): Server
            {
                return $this->testServer;
            }
        };

        $this->inject($controller, 'securityContext', $this->securityContext);
        $this->inject($controller, 'oauthServerFactory', $this->oauthServerFactory);
        $this->inject($controller, 'mcpUserContext', new McpUserContext());

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
