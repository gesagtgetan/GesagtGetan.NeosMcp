<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthAccessToken;
use GesagtGetan\NeosMcp\OAuth\Entity\OAuthScope;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthAccessTokenRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use PHPUnit\Framework\TestCase;

class OAuthAccessTokenRepositoryTest extends TestCase
{
    private OAuthAccessTokenRepository $subject;

    protected function setUp(): void
    {
        $this->subject = new OAuthAccessTokenRepository();
    }

    /**
     * @test
     */
    public function getNewTokenAssemblesTokenWithClientAndScopes(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client');

        $scopes = [new OAuthScope('mcp')];

        $token = $this->subject->getNewToken($client, $scopes, 'user-42');

        self::assertInstanceOf(OAuthAccessToken::class, $token);
        self::assertSame('test-client', $token->getClient()->getIdentifier());
        self::assertSame('user-42', $token->getUserIdentifier());
        self::assertCount(1, $token->getScopes());
        self::assertSame('mcp', $token->getScopes()[0]->getIdentifier());
    }

    /**
     * @test
     */
    public function getNewTokenHandlesNullUserIdentifier(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);

        $token = $this->subject->getNewToken($client, []);

        self::assertNull($token->getUserIdentifier());
    }

    /**
     * @test
     */
    public function persistIsNoOp(): void
    {
        $token = $this->createMock(OAuthAccessToken::class);

        // Should not throw.
        $this->subject->persistNewAccessToken($token);
        $this->addToAssertionCount(1);
    }

    /**
     * @test
     */
    public function revokeIsNoOp(): void
    {
        // Should not throw.
        $this->subject->revokeAccessToken('any-token-id');
        $this->addToAssertionCount(1);
    }

    /**
     * @test
     */
    public function accessTokenIsNeverRevoked(): void
    {
        self::assertFalse($this->subject->isAccessTokenRevoked('any-token-id'));
    }
}
