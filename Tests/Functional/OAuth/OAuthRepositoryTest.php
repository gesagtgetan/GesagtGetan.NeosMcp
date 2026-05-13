<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Functional\OAuth;

use Doctrine\ORM\EntityManagerInterface;
use GesagtGetan\NeosMcp\OAuth\Entity\OAuthAccessToken;
use GesagtGetan\NeosMcp\OAuth\Entity\OAuthClient;
use GesagtGetan\NeosMcp\OAuth\Entity\OAuthScope;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthAuthCodeRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthClientRepository;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthRefreshTokenRepository;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

class OAuthRepositoryTest extends FunctionalTestCase
{
    private OAuthClientRepository $clientRepository;
    private OAuthAuthCodeRepository $authCodeRepository;
    private OAuthRefreshTokenRepository $refreshTokenRepository;
    private PersistenceManagerInterface $persistence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientRepository = $this->objectManager->get(OAuthClientRepository::class);
        $this->authCodeRepository = $this->objectManager->get(OAuthAuthCodeRepository::class);
        $this->refreshTokenRepository = $this->objectManager->get(OAuthRefreshTokenRepository::class);
        $this->persistence = $this->objectManager->get(PersistenceManagerInterface::class);

        // Clean stale data from previous test runs to prevent unique constraint violations.
        // Same pattern as McpCommandControllerTest which manually cleans Doctrine tables.
        $this->refreshTokenRepository->removeAll();
        $this->authCodeRepository->removeAll();
        $this->clientRepository->removeAll();
        $this->persistence->persistAll();
    }

    // -----------------------------------------------------------------------
    // OAuthClientRepository
    // -----------------------------------------------------------------------

    #[Test]
    public function clientPersistsAndLoadsCorrectly(): void
    {
        $redirectUris = ['https://example.com/callback', 'https://example.com/callback2'];
        $grantTypes = ['authorization_code', 'refresh_token'];

        $client = new OAuthClient(
            clientId: 'test-client-persist',
            clientName: 'Test Client',
            redirectUris: $redirectUris,
            grantTypes: $grantTypes,
            tokenEndpointAuthMethod: 'client_secret_post',
            isConfidential: true,
            clientSecret: password_hash('s3cret', PASSWORD_BCRYPT),
        );

        $this->clientRepository->add($client);
        $this->persistence->persistAll();
        $this->clearEntityManager();

        $loaded = $this->clientRepository->findOneByClientId('test-client-persist');

        self::assertNotNull($loaded);
        self::assertSame('test-client-persist', $loaded->getIdentifier());
        self::assertSame('Test Client', $loaded->getName());
        self::assertSame($redirectUris, $loaded->getRedirectUri());
        self::assertSame($grantTypes, $loaded->getGrantTypes());
        self::assertSame('client_secret_post', $loaded->getTokenEndpointAuthMethod());
        self::assertTrue($loaded->isConfidential());
        self::assertNotNull($loaded->getClientSecret());
        self::assertTrue(password_verify('s3cret', $loaded->getClientSecret()));
        self::assertEqualsWithDelta(time(), $loaded->getCreatedAt()->getTimestamp(), 5);
    }

    #[Test]
    public function validateClientVerifiesPasswordHash(): void
    {
        $client = new OAuthClient(
            clientId: 'confidential-client',
            clientName: 'Confidential Client',
            redirectUris: ['https://example.com/callback'],
            grantTypes: ['authorization_code'],
            tokenEndpointAuthMethod: 'client_secret_post',
            isConfidential: true,
            clientSecret: password_hash('correct-secret', PASSWORD_BCRYPT),
        );

        $this->clientRepository->add($client);
        $this->persistence->persistAll();
        $this->clearEntityManager();

        self::assertTrue($this->clientRepository->validateClient('confidential-client', 'correct-secret', 'authorization_code'));
        self::assertFalse($this->clientRepository->validateClient('confidential-client', 'wrong-secret', 'authorization_code'));
        self::assertFalse($this->clientRepository->validateClient('confidential-client', 'correct-secret', 'client_credentials'));
    }

    #[Test]
    public function clientIdHasUniqueConstraint(): void
    {
        $metadata = $this->objectManager->get(EntityManagerInterface::class)
            ->getClassMetadata(OAuthClient::class);

        $mapping = $metadata->getFieldMapping('clientId');

        self::assertTrue($mapping['unique'] ?? false, 'clientId column must have a unique constraint');
    }

    // -----------------------------------------------------------------------
    // OAuthAuthCodeRepository (league entry points)
    // -----------------------------------------------------------------------

    #[Test]
    public function authCodePersistsAndLoadsCorrectly(): void
    {
        $expiresAt = new \DateTimeImmutable('+10 minutes');

        $client = new OAuthClient(
            clientId: 'auth-code-client',
            clientName: 'Auth Code Client',
            redirectUris: ['https://example.com/callback'],
        );
        $this->clientRepository->add($client);

        // League creates auth codes via getNewAuthCode() + persistNewAuthCode()
        $authCode = $this->authCodeRepository->getNewAuthCode();
        $authCode->setIdentifier('test-auth-code-abc');
        $authCode->setClient($client);
        $authCode->setUserIdentifier('test-user-123');
        $authCode->setRedirectUri('https://example.com/callback');
        $authCode->addScope(new OAuthScope('mcp'));
        $authCode->setExpiryDateTime($expiresAt);

        $this->authCodeRepository->persistNewAuthCode($authCode);
        $this->persistence->persistAll();
        $this->clearEntityManager();

        $loaded = $this->authCodeRepository->findOneByCode('test-auth-code-abc');

        self::assertNotNull($loaded);
        self::assertSame('test-auth-code-abc', $loaded->getIdentifier());
        self::assertSame('test-user-123', $loaded->getUserIdentifier());
        self::assertSame('https://example.com/callback', $loaded->getRedirectUri());
        self::assertFalse($loaded->isRevoked());
        self::assertEquals($expiresAt->getTimestamp(), $loaded->getExpiryDateTime()->getTimestamp());

        // Transient scopeEntities are NOT restored from the database
        self::assertEmpty($loaded->getScopes());

        // But the persisted scopes string must survive the round-trip
        $r = new \ReflectionProperty($loaded, 'scopes');
        self::assertSame('mcp', $r->getValue($loaded));

        // clientId (denormalized from the transient clientEntity) must survive
        $r = new \ReflectionProperty($loaded, 'clientId');
        self::assertSame('auth-code-client', $r->getValue($loaded));
    }

    #[Test]
    public function revokeAuthCodeUpdatesDatabase(): void
    {
        $client = new OAuthClient(
            clientId: 'revoke-test-client',
            clientName: 'Revoke Test',
            redirectUris: [],
        );
        $this->clientRepository->add($client);

        $authCode = $this->authCodeRepository->getNewAuthCode();
        $authCode->setIdentifier('revoke-this-code');
        $authCode->setClient($client);
        $authCode->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));

        $this->authCodeRepository->persistNewAuthCode($authCode);
        $this->persistence->persistAll();

        $this->authCodeRepository->revokeAuthCode('revoke-this-code');
        $this->persistence->persistAll();
        $this->clearEntityManager();

        self::assertTrue($this->authCodeRepository->isAuthCodeRevoked('revoke-this-code'));
    }

    #[Test]
    public function isAuthCodeRevokedReturnsTrueForMissingCode(): void
    {
        self::assertTrue($this->authCodeRepository->isAuthCodeRevoked('nonexistent-code'));
    }

    // -----------------------------------------------------------------------
    // OAuthRefreshTokenRepository (league entry points)
    // -----------------------------------------------------------------------

    #[Test]
    public function refreshTokenPersistsAndLoadsCorrectly(): void
    {
        $expiresAt = new \DateTimeImmutable('+30 days');

        $client = new OAuthClient(
            clientId: 'refresh-token-client',
            clientName: 'Refresh Token Client',
            redirectUris: ['https://example.com/callback'],
        );
        $this->clientRepository->add($client);

        $accessToken = new OAuthAccessToken();
        $accessToken->setIdentifier('access-token-id-xyz');
        $accessToken->setClient($client);
        $accessToken->setUserIdentifier('user-456');
        $accessToken->addScope(new OAuthScope('mcp'));
        $accessToken->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));

        // League creates refresh tokens via getNewRefreshToken() + persistNewRefreshToken()
        $refreshToken = $this->refreshTokenRepository->getNewRefreshToken();
        $refreshToken->setIdentifier('refresh-token-abc');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime($expiresAt);

        $this->refreshTokenRepository->persistNewRefreshToken($refreshToken);
        $this->persistence->persistAll();
        $this->clearEntityManager();

        $loaded = $this->refreshTokenRepository->findOneByToken('refresh-token-abc');

        self::assertNotNull($loaded);
        self::assertSame('refresh-token-abc', $loaded->getIdentifier());
        self::assertFalse($loaded->isRevoked());
        self::assertEquals($expiresAt->getTimestamp(), $loaded->getExpiryDateTime()->getTimestamp());

        // Verify denormalized fields from setAccessToken() survived the round-trip.
        // These have no public getters — they're persisted columns read via reflection.
        $r = new \ReflectionClass($loaded);
        self::assertSame('access-token-id-xyz', $r->getProperty('accessTokenId')->getValue($loaded));
        self::assertSame('refresh-token-client', $r->getProperty('clientId')->getValue($loaded));
        self::assertSame('user-456', $r->getProperty('userIdentifier')->getValue($loaded));
        self::assertSame('mcp', $r->getProperty('scopes')->getValue($loaded));
    }

    #[Test]
    public function revokeRefreshTokenUpdatesDatabase(): void
    {
        $client = new OAuthClient(
            clientId: 'revoke-refresh-client',
            clientName: 'Revoke Refresh',
            redirectUris: [],
        );
        $this->clientRepository->add($client);

        $accessToken = new OAuthAccessToken();
        $accessToken->setIdentifier('access-for-revoke');
        $accessToken->setClient($client);
        $accessToken->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));

        $refreshToken = $this->refreshTokenRepository->getNewRefreshToken();
        $refreshToken->setIdentifier('revoke-this-refresh');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime(new \DateTimeImmutable('+30 days'));

        $this->refreshTokenRepository->persistNewRefreshToken($refreshToken);
        $this->persistence->persistAll();

        $this->refreshTokenRepository->revokeRefreshToken('revoke-this-refresh');
        $this->persistence->persistAll();
        $this->clearEntityManager();

        self::assertTrue($this->refreshTokenRepository->isRefreshTokenRevoked('revoke-this-refresh'));
    }

    #[Test]
    public function isRefreshTokenRevokedReturnsTrueForMissingToken(): void
    {
        self::assertTrue($this->refreshTokenRepository->isRefreshTokenRevoked('nonexistent-token'));
    }

    // -----------------------------------------------------------------------
    // OAuthServerFactory
    // -----------------------------------------------------------------------

    #[Test]
    public function ensureClientCreatesClientInDatabase(): void
    {
        $factory = $this->objectManager->get(OAuthServerFactory::class);

        // Override settings via reflection — the Testing context has null client credentials
        $reflection = new \ReflectionProperty($factory, 'settings');
        $reflection->setValue($factory, [
            'enabled' => true,
            'client' => [
                'id' => 'factory-test-client',
                'secret' => 'factory-test-secret',
                'knownRedirectUris' => ['https://example.com/callback'],
            ],
        ]);

        $factory->ensureClient();

        $loaded = $this->clientRepository->findOneByClientId('factory-test-client');

        self::assertNotNull($loaded);
        self::assertSame('factory-test-client', $loaded->getIdentifier());
        self::assertSame('MCP Client', $loaded->getName());
        self::assertSame(['https://example.com/callback'], $loaded->getRedirectUri());
        self::assertSame(['authorization_code', 'refresh_token'], $loaded->getGrantTypes());
        self::assertSame('client_secret_post', $loaded->getTokenEndpointAuthMethod());
        self::assertTrue($loaded->isConfidential());
        self::assertNotNull($loaded->getClientSecret());
        self::assertTrue(password_verify('factory-test-secret', $loaded->getClientSecret()));
    }

    #[Test]
    public function ensureClientUpdatesExistingClient(): void
    {
        $factory = $this->objectManager->get(OAuthServerFactory::class);
        $reflection = new \ReflectionProperty($factory, 'settings');

        $reflection->setValue($factory, [
            'enabled' => true,
            'client' => [
                'id' => 'update-client',
                'secret' => 'original-secret',
                'knownRedirectUris' => ['https://example.com/callback'],
            ],
        ]);

        $factory->ensureClient();
        $original = $this->clientRepository->findOneByClientId('update-client');
        self::assertNotNull($original);
        self::assertSame(['https://example.com/callback'], $original->getRedirectUri());

        // Update settings and re-run — must update redirect URIs and secret
        $reflection->setValue($factory, [
            'enabled' => true,
            'client' => [
                'id' => 'update-client',
                'secret' => 'new-secret',
                'knownRedirectUris' => ['https://example.com/callback', 'http://localhost:6274/callback'],
            ],
        ]);

        $factory->ensureClient();
        $this->clearEntityManager();

        $updated = $this->clientRepository->findOneByClientId('update-client');
        self::assertNotNull($updated);
        self::assertSame(['https://example.com/callback', 'http://localhost:6274/callback'], $updated->getRedirectUri());
        $secret = $updated->getClientSecret();
        self::assertNotNull($secret);
        self::assertTrue(password_verify('new-secret', $secret));
        self::assertSame($original->getCreatedAt()->getTimestamp(), $updated->getCreatedAt()->getTimestamp());
    }

    private function clearEntityManager(): void
    {
        $this->objectManager->get(EntityManagerInterface::class)->clear();
    }
}
