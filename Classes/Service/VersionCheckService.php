<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Service;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Configuration\ConfigurationManager;

/**
 * Checks whether a newer stable release of this package has been published and,
 * if so, produces a one-line notice. The result feeds the MCP `initialize`
 * instructions, so the user is told about updates on connect.
 *
 * Every step is fail-silent: any error (offline, malformed response) yields
 * no notice and never disrupts the server handshake. The
 * lookup is skipped entirely when the installed version is not a comparable
 * stable release (e.g. a dev/branch checkout), and the latest-version lookup is
 * cached so the network is hit at most once per cache lifetime, not per connect.
 */
#[Flow\Scope('singleton')]
final readonly class VersionCheckService
{
    private const string PACKAGE_NAME = 'gesagtgetan/neos-mcp';
    private const string CACHE_NAME = 'GesagtGetan_NeosMcp_VersionCheck';
    private const string CACHE_ENTRY = 'latestStableVersion';

    private bool $enabled;
    private string $repositoryUrl;

    public function __construct(
        private ClientInterface $httpClient,
        private CacheManager $cacheManager,
        ConfigurationManager $configurationManager,
    ) {
        // Read settings here rather than via #[Flow\InjectConfiguration]: that attribute needs a
        // non-private (property-injected) target, which conflicts with both this readonly class and
        // the project's constructor-promotion-is-private code style. Constructor injection keeps the
        // class final readonly and trivially constructable in tests.
        $enabled = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'GesagtGetan.NeosMcp.versionCheck.enabled');
        $this->enabled = $enabled === true;

        $repositoryUrl = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'GesagtGetan.NeosMcp.versionCheck.repositoryUrl');
        $this->repositoryUrl = is_string($repositoryUrl) ? $repositoryUrl : '';
    }

    /**
     * A human-readable update notice, or null when nothing newer is available
     * (or the check is disabled, the install is a dev build, or any error occurs).
     */
    public function getUpdateNotice(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $installed = $this->installedStableVersion();
            if ($installed === null) {
                return null;
            }

            return $this->buildNotice($installed, $this->latestStableVersion());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pure comparison: an agent-facing instruction to inform the user of an update
     * (phrased so the agent relays it in the user's language), or null when the
     * latest is missing or not strictly newer. Handles a leading `v` on either side.
     */
    public function buildNotice(string $installedVersion, ?string $latestVersion): ?string
    {
        if ($latestVersion === null || $latestVersion === '') {
            return null;
        }

        if (!version_compare(ltrim($latestVersion, 'vV'), ltrim($installedVersion, 'vV'), '>')) {
            return null;
        }

        return sprintf(
            'A newer version of this Neos MCP server is available: %s (currently running %s). Tell the user once, in their own language, that an update is available and they can ask their developer to install it; then continue with their request.',
            $latestVersion,
            $installedVersion,
        );
    }

    /**
     * Pure selection: the highest stable version in a Composer p2 metadata
     * payload, ignoring dev/pre-release entries. Null if none.
     *
     * @param array<mixed, mixed> $payload
     */
    public function latestStableVersionFromPayload(array $payload): ?string
    {
        $packages = $payload['packages'] ?? null;
        $releases = is_array($packages) ? ($packages[self::PACKAGE_NAME] ?? null) : null;
        if (!is_array($releases)) {
            return null;
        }

        $latest = null;
        foreach ($releases as $release) {
            $version = is_array($release) ? ($release['version'] ?? null) : null;
            if (!is_string($version) || VersionParser::parseStability($version) !== 'stable') {
                continue;
            }

            if ($latest === null || version_compare(ltrim($version, 'vV'), ltrim($latest, 'vV'), '>')) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * The installed package version, or null when it is not a comparable stable
     * release (dev/branch checkout, as used while developing this package).
     */
    private function installedStableVersion(): ?string
    {
        $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        if ($version === null || VersionParser::parseStability($version) !== 'stable') {
            return null;
        }

        return $version;
    }

    /**
     * The latest stable version from the configured Composer repository, cached
     * for the cache lifetime. An empty string is cached on failure so a failed
     * lookup is not retried on every connect.
     */
    private function latestStableVersion(): ?string
    {
        $cache = $this->cacheManager->getCache(self::CACHE_NAME);

        $cached = $cache->get(self::CACHE_ENTRY);
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $latest = $this->fetchLatestStableVersion();
        $cache->set(self::CACHE_ENTRY, $latest ?? '');

        return $latest;
    }

    private function fetchLatestStableVersion(): ?string
    {
        $url = rtrim($this->repositoryUrl, '/') . '/p2/' . self::PACKAGE_NAME . '.json';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'connect_timeout' => 2,
                'timeout' => 3,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException) {
            return null;
        }

        return is_array($payload) ? $this->latestStableVersionFromPayload($payload) : null;
    }
}
