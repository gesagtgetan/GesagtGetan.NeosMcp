<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Controller;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Controller\McpHttpController;
use GesagtGetan\NeosMcp\McpToolProvider;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Tests\UnitTestCase;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PHPUnit\Framework\MockObject\MockObject;

class McpHttpControllerTest extends UnitTestCase
{
    private const BEARER_TOKEN = 'test-secret-token';

    private McpHttpController $subject;
    private SecurityContext&MockObject $securityContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new McpHttpController();
        $this->securityContext = $this->createMock(SecurityContext::class);
        $this->securityContext->method('withoutAuthorizationChecks')->willReturnCallback(
            static fn (\Closure $callback): mixed => $callback(),
        );

        $this->inject($this->subject, 'securityContext', $this->securityContext);
        $this->inject($this->subject, 'httpTransportSettings', ['enabled' => true, 'bearerToken' => self::BEARER_TOKEN]);
    }

    /**
     * @test
     */
    public function disabledEndpointReturns404(): void
    {
        $this->inject($this->subject, 'httpTransportSettings', ['enabled' => false, 'bearerToken' => self::BEARER_TOKEN]);
        $this->injectRequest('{}', 'Bearer ' . self::BEARER_TOKEN);

        $response = $this->subject->handleAction();

        self::assertSame(404, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('Not found', $body['error']);
    }

    /**
     * @test
     */
    public function nullBearerTokenReturns404(): void
    {
        $this->inject($this->subject, 'httpTransportSettings', ['enabled' => true, 'bearerToken' => null]);
        $this->injectRequest('{}', 'Bearer some-token');

        $response = $this->subject->handleAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function missingAuthorizationHeaderReturns401(): void
    {
        $this->injectRequest('{}', '');

        $response = $this->subject->handleAction();

        self::assertSame(401, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertSame('Unauthorized', $body['error']);
    }

    /**
     * @test
     */
    public function wrongBearerTokenReturns401(): void
    {
        $this->injectRequest('{}', 'Bearer wrong-token');

        $response = $this->subject->handleAction();

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function invalidJsonReturns400WithParseError(): void
    {
        $this->injectRequest('not-json', 'Bearer ' . self::BEARER_TOKEN);

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
    public function notificationReturns400(): void
    {
        $this->injectRequest('{"jsonrpc":"2.0","method":"notifications/initialized"}', 'Bearer ' . self::BEARER_TOKEN);

        $response = $this->subject->handleAction();

        self::assertSame(400, $response->getStatusCode());
        $body = $this->decodeJsonBody((string) $response->getBody());
        self::assertIsArray($body['error']);
        self::assertSame(-32600, $body['error']['code']);
    }

    /**
     * @test
     */
    public function toolsListReturnsJsonRpcResponse(): void
    {
        $controller = $this->createControllerWithMockServer();
        $this->injectRequestInto(
            $controller,
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
            'Bearer ' . self::BEARER_TOKEN,
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
        $httpRequest = new ServerRequest('POST', 'http://localhost/neos/mcp', [], $body);
        if ($authorizationHeader !== '') {
            $httpRequest = $httpRequest->withHeader('Authorization', $authorizationHeader);
        }

        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);

        $this->inject($controller, 'request', $actionRequest);
    }

    /**
     * Creates a controller subclass with buildServer() overridden to avoid final-class mocking.
     *
     * ContentRepositoryRegistry and ContentRepository are both final, so we bypass them entirely
     * by building the Server from a ContentRepositoryFacade mock directly.
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

        $redirectStorage = $this->createMock(RedirectStorageInterface::class);
        $toolProvider = new McpToolProvider($facade, WorkspaceName::fromString('test-workspace'), $redirectStorage);

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

        $server = $builder->build();

        $controller = new class ($server) extends McpHttpController {
            public function __construct(private readonly Server $testServer)
            {
            }

            protected function buildServer(): Server
            {
                return $this->testServer;
            }
        };

        $this->inject($controller, 'securityContext', $this->securityContext);
        $this->inject($controller, 'httpTransportSettings', ['enabled' => true, 'bearerToken' => self::BEARER_TOKEN]);

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
