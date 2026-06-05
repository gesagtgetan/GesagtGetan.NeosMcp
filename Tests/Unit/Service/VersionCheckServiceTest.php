<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\Service\VersionCheckService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

class VersionCheckServiceTest extends UnitTestCase
{
    #[Test]
    public function buildNoticeReportsAvailableUpdateWithBothVersions(): void
    {
        $notice = $this->subject()->buildNotice('1.2.1', '1.3.0');

        self::assertNotNull($notice);
        self::assertStringContainsString('1.3.0', $notice);
        self::assertStringContainsString('1.2.1', $notice);
        // Phrased as an instruction to the agent (relay in the user's language), not a bare English statement.
        self::assertStringContainsStringIgnoringCase('language', $notice);
    }

    #[Test]
    public function buildNoticeReturnsNullWhenInstalledIsCurrent(): void
    {
        self::assertNull($this->subject()->buildNotice('1.3.0', '1.3.0'));
    }

    #[Test]
    public function buildNoticeReturnsNullWhenLatestIsOlder(): void
    {
        self::assertNull($this->subject()->buildNotice('1.3.0', '1.2.9'));
    }

    #[Test]
    public function buildNoticeNormalizesLeadingVOnEitherSide(): void
    {
        self::assertNotNull($this->subject()->buildNotice('v1.2.0', '1.3.0'));
        self::assertNotNull($this->subject()->buildNotice('1.2.0', 'v1.3.0'));
        self::assertNull($this->subject()->buildNotice('v1.3.0', 'v1.3.0'));
    }

    #[Test]
    public function buildNoticeReturnsNullWhenLatestIsMissing(): void
    {
        self::assertNull($this->subject()->buildNotice('1.2.0', null));
        self::assertNull($this->subject()->buildNotice('1.2.0', ''));
    }

    #[Test]
    public function latestStableVersionFromPayloadReturnsHighestStableRelease(): void
    {
        $latest = $this->subject()->latestStableVersionFromPayload([
            'packages' => [
                'gesagtgetan/neos-mcp' => [
                    ['version' => '1.2.0'],
                    ['version' => '1.3.0'],
                    ['version' => '1.2.9'],
                ],
            ],
        ]);

        self::assertSame('1.3.0', $latest);
    }

    #[Test]
    public function latestStableVersionFromPayloadIgnoresDevAndPreReleaseEntries(): void
    {
        $latest = $this->subject()->latestStableVersionFromPayload([
            'packages' => [
                'gesagtgetan/neos-mcp' => [
                    ['version' => '1.3.0'],
                    ['version' => '2.0.0-beta1'],
                    ['version' => 'dev-main'],
                ],
            ],
        ]);

        self::assertSame('1.3.0', $latest);
    }

    #[Test]
    public function latestStableVersionFromPayloadReturnsNullWhenNoStableReleaseExists(): void
    {
        self::assertNull($this->subject()->latestStableVersionFromPayload([
            'packages' => ['gesagtgetan/neos-mcp' => [['version' => 'dev-main']]],
        ]));
        self::assertNull($this->subject()->latestStableVersionFromPayload(['packages' => []]));
        self::assertNull($this->subject()->latestStableVersionFromPayload([]));
    }

    #[Test]
    public function getUpdateNoticeReturnsNullAndMakesNoRequestWhenDisabled(): void
    {
        // MockHandler with no queued responses: if a request were made it would throw.
        $subject = $this->subject(enabled: false, handler: new MockHandler([]));

        self::assertNull($subject->getUpdateNotice());
    }

    #[Test]
    public function getUpdateNoticeReturnsNullWhenNothingNewerIsAvailable(): void
    {
        // In the dev/path checkout the installed version is non-stable, so the lookup is
        // skipped; if it were a stable install, the older payload still yields no notice.
        $payload = (string) json_encode([
            'packages' => ['gesagtgetan/neos-mcp' => [['version' => '0.0.1']]],
        ]);
        $subject = $this->subject(enabled: true, handler: new MockHandler([new Response(200, [], $payload)]));

        self::assertNull($subject->getUpdateNotice());
    }

    private function subject(bool $enabled = true, ?MockHandler $handler = null): VersionCheckService
    {
        $client = new Client(['handler' => HandlerStack::create($handler ?? new MockHandler([]))]);

        $cache = $this->createMock(VariableFrontend::class);
        $cache->method('get')->willReturn(false);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        $type = ConfigurationManager::CONFIGURATION_TYPE_SETTINGS;
        $configurationManager = $this->createMock(ConfigurationManager::class);
        $configurationManager->method('getConfiguration')->willReturnMap([
            [$type, 'GesagtGetan.NeosMcp.versionCheck.enabled', $enabled],
            [$type, 'GesagtGetan.NeosMcp.versionCheck.repositoryUrl', 'https://repo.packagist.org'],
        ]);

        return new VersionCheckService($client, $cacheManager, $configurationManager);
    }
}
