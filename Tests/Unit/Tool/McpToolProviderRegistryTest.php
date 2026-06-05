<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Service\VersionCheckService;
use GesagtGetan\NeosMcp\Tool\McpRequestContext;
use GesagtGetan\NeosMcp\Tool\McpToolProvider;
use GesagtGetan\NeosMcp\Tool\McpToolProviderRegistry;
use GuzzleHttp\Client;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Tests\UnitTestCase;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use PHPUnit\Framework\Attributes\Test;

class McpToolProviderRegistryTest extends UnitTestCase
{
    #[Test]
    public function registerAllInvokesEveryDiscoveredProviderInOrder(): void
    {
        /** @var list<string> $invocations */
        $invocations = [];
        $record = static function (string $name) use (&$invocations): void {
            $invocations[] = $name;
        };

        $providerA = new class ($record) implements McpToolProvider {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function registerTools(ServerBuilder $builder, BasicContainer $container, McpRequestContext $context): ServerBuilder
            {
                ($this->record)('A');

                return $builder;
            }
        };

        $providerB = new class ($record) implements McpToolProvider {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function registerTools(ServerBuilder $builder, BasicContainer $container, McpRequestContext $context): ServerBuilder
            {
                ($this->record)('B');

                return $builder;
            }
        };

        $reflectionService = $this->createMock(ReflectionService::class);
        $reflectionService->method('getAllImplementationClassNamesForInterface')->willReturn(['A', 'B']);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(static fn (string $name): object => match ($name) {
            'A' => $providerA,
            'B' => $providerB,
            default => throw new \RuntimeException('unexpected ' . $name),
        });

        $registry = new McpToolProviderRegistry($reflectionService, $objectManager, $this->versionCheckService());

        $facade = $this->createMock(ContentRepositoryFacade::class);
        $context = new McpRequestContext($facade, WorkspaceName::fromString('ws'));

        $registry->registerAll(
            Server::make()->withServerInfo('t', '0.0.0'),
            new BasicContainer(),
            $context,
        );

        self::assertSame(['A', 'B'], $invocations);
    }

    #[Test]
    public function nonImplementingClassesAreSilentlySkipped(): void
    {
        $reflectionService = $this->createMock(ReflectionService::class);
        $reflectionService->method('getAllImplementationClassNamesForInterface')
            ->willReturn(['SomeClass']);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn(new \stdClass());

        $registry = new McpToolProviderRegistry($reflectionService, $objectManager, $this->versionCheckService());

        $facade = $this->createMock(ContentRepositoryFacade::class);
        $builder = Server::make()->withServerInfo('t', '0.0.0');

        $result = $registry->registerAll(
            $builder,
            new BasicContainer(),
            new McpRequestContext($facade, WorkspaceName::fromString('ws')),
        );

        self::assertSame($builder, $result);
    }

    /**
     * A disabled, inert VersionCheckService — the registry only needs an instance
     * to construct; these tests exercise registerAll(), not the version notice.
     * VersionCheckService is final readonly and cannot be mocked.
     */
    private function versionCheckService(): VersionCheckService
    {
        return new VersionCheckService(
            new Client(),
            $this->createMock(CacheManager::class),
            $this->createMock(ConfigurationManager::class),
        );
    }
}
