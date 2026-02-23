<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Service;

use Defuse\Crypto\Key;
use GesagtGetan\NeosMcp\OAuth\Entity\OAuthClient;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthAccessTokenRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthAuthCodeRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthClientRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthRefreshTokenRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthScopeRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Utility\Environment;

/**
 * Creates configured league AuthorizationServer and ResourceServer instances.
 * Auto-generates RSA keys and encryption key on first use.
 *
 * @Flow\Scope("singleton")
 */
class OAuthServerFactory
{
    #[Flow\Inject]
    protected OAuthClientRepository $clientRepository;

    #[Flow\Inject]
    protected OAuthAccessTokenRepository $accessTokenRepository;

    #[Flow\Inject]
    protected OAuthAuthCodeRepository $authCodeRepository;

    #[Flow\Inject]
    protected OAuthRefreshTokenRepository $refreshTokenRepository;

    #[Flow\Inject]
    protected OAuthScopeRepository $scopeRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected Environment $environment;

    /** @var array{enabled?: bool, issuer?: string|null, client?: array{id?: string|null, secret?: string|null, knownRedirectUris?: list<string>}, accessTokenLifetime?: int, refreshTokenLifetime?: int, authorizationCodeLifetime?: int, privateKeyFile?: string, publicKeyFile?: string, encryptionKeyFile?: string, corsAllowedOrigins?: list<string>} */
    #[Flow\InjectConfiguration(path: 'oauth', package: 'GesagtGetan.NeosMcp')]
    protected array $settings;

    public function isEnabled(): bool
    {
        return ($this->settings['enabled'] ?? false) === true;
    }

    public function getIssuer(): string
    {
        return $this->settings['issuer'] ?? throw new \RuntimeException('GesagtGetan.NeosMcp.oauth.issuer must be configured', 1740000001);
    }

    /**
     * Returns the CORS Access-Control-Allow-Origin value for the given request origin.
     * Returns null if the origin is not allowed.
     */
    public function getCorsAllowedOrigin(string $requestOrigin): ?string
    {
        $allowed = $this->settings['corsAllowedOrigins'] ?? ['*'];

        if (in_array('*', $allowed, true)) {
            return '*';
        }

        if (in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }

    public function getConfiguredClientId(): string
    {
        return $this->settings['client']['id'] ?? throw new \RuntimeException('GesagtGetan.NeosMcp.oauth.client.id must be configured', 1740000011);
    }

    /**
     * Ensures the configured OAuth client exists in the database.
     * Creates it on first call, no-ops on subsequent calls.
     */
    public function ensureClient(): void
    {
        $clientConfig = $this->settings['client'] ?? [];
        $clientId = $clientConfig['id'] ?? null;
        $clientSecret = $clientConfig['secret'] ?? null;

        if ($clientId === null || $clientId === '' || $clientSecret === null || $clientSecret === '') {
            throw new \RuntimeException('GesagtGetan.NeosMcp.oauth.client.id and .secret must be configured', 1740000010);
        }

        if ($this->clientRepository->findOneByClientId($clientId) !== null) {
            return;
        }

        $client = new OAuthClient(
            clientId: $clientId,
            clientName: 'MCP Client',
            redirectUris: $clientConfig['knownRedirectUris'] ?? [],
            grantTypes: ['authorization_code', 'refresh_token'],
            tokenEndpointAuthMethod: 'client_secret_post',
            isConfidential: true,
            clientSecret: password_hash($clientSecret, PASSWORD_BCRYPT),
        );

        $this->clientRepository->add($client);
        $this->persistenceManager->persistAll();
    }

    public function createAuthorizationServer(): AuthorizationServer
    {
        $this->ensureClient();

        $privateKey = new CryptKey($this->ensurePrivateKey(), null, false);
        $encryptionKey = $this->ensureEncryptionKey();

        $server = new AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $this->scopeRepository,
            $privateKey,
            $encryptionKey,
        );

        $authCodeTtl = new \DateInterval('PT' . ($this->settings['authorizationCodeLifetime'] ?? 600) . 'S');
        $authCodeGrant = new AuthCodeGrant($this->authCodeRepository, $this->refreshTokenRepository, $authCodeTtl);

        $refreshTokenTtl = new \DateInterval('PT' . ($this->settings['refreshTokenLifetime'] ?? 2592000) . 'S');
        $authCodeGrant->setRefreshTokenTTL($refreshTokenTtl);

        $accessTokenTtl = new \DateInterval('PT' . ($this->settings['accessTokenLifetime'] ?? 3600) . 'S');
        $server->enableGrantType($authCodeGrant, $accessTokenTtl);

        $refreshTokenGrant = new RefreshTokenGrant($this->refreshTokenRepository);
        $refreshTokenGrant->setRefreshTokenTTL($refreshTokenTtl);
        $server->enableGrantType($refreshTokenGrant, $accessTokenTtl);

        $server->setDefaultScope('mcp');

        return $server;
    }

    public function createResourceServer(): ResourceServer
    {
        $publicKey = new CryptKey($this->ensurePublicKey(), null, false);

        return new ResourceServer($this->accessTokenRepository, $publicKey);
    }

    private function ensurePrivateKey(): string
    {
        $path = $this->resolveKeyPath($this->settings['privateKeyFile'] ?? 'oauth-private.key');
        if (!file_exists($path)) {
            $this->generateKeyPair();
        }

        return $path;
    }

    private function ensurePublicKey(): string
    {
        $path = $this->resolveKeyPath($this->settings['publicKeyFile'] ?? 'oauth-public.key');
        if (!file_exists($path)) {
            $this->generateKeyPair();
        }

        return $path;
    }

    private function ensureEncryptionKey(): Key
    {
        $path = $this->resolveKeyPath($this->settings['encryptionKeyFile'] ?? 'oauth-encryption.key');

        if (!file_exists($path)) {
            $key = Key::createNewRandomKey();
            $this->writeKeyFile($path, $key->saveToAsciiSafeString());

            return $key;
        }

        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            throw new \RuntimeException('Failed to read encryption key at ' . $path, 1740000002);
        }

        return Key::loadFromAsciiSafeString($content);
    }

    private function generateKeyPair(): void
    {
        $privateKeyFile = $this->resolveKeyPath($this->settings['privateKeyFile'] ?? 'oauth-private.key');
        $publicKeyFile = $this->resolveKeyPath($this->settings['publicKeyFile'] ?? 'oauth-public.key');

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyPair === false) {
            throw new \RuntimeException('Failed to generate RSA key pair', 1740000003);
        }

        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);
        $details = openssl_pkey_get_details($keyPair);

        if (!is_string($privateKeyPem) || $privateKeyPem === '' || $details === false || !isset($details['key']) || !is_string($details['key'])) {
            throw new \RuntimeException('Failed to extract key material', 1740000004);
        }

        $this->writeKeyFile($privateKeyFile, $privateKeyPem);
        $this->writeKeyFile($publicKeyFile, $details['key']);
    }

    private function resolveKeyPath(string $configured): string
    {
        if (str_starts_with($configured, '/') || str_starts_with($configured, 'file://')) {
            return $configured;
        }

        $dir = $this->environment->getPathToTemporaryDirectory() . '/../Persistent/GesagtGetan.NeosMcp';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir . '/' . $configured;
    }

    private function writeKeyFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($path, $content);
        chmod($path, 0600);
    }
}
