<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Repository;

use GesagtGetan\NeosMcp\OAuth\Entity\OAuthScope;
use GesagtGetan\NeosMcp\OAuth\Repository\OAuthScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuthScopeRepositoryTest extends TestCase
{
    private OAuthScopeRepository $subject;

    protected function setUp(): void
    {
        $this->subject = new OAuthScopeRepository();
    }

    #[Test]
    public function mcpScopeIsResolved(): void
    {
        $scope = $this->subject->getScopeEntityByIdentifier('mcp');

        self::assertInstanceOf(OAuthScope::class, $scope);
        self::assertSame('mcp', $scope->getIdentifier());
    }

    #[Test]
    public function unknownScopeReturnsNull(): void
    {
        self::assertNull($this->subject->getScopeEntityByIdentifier('admin'));
        self::assertNull($this->subject->getScopeEntityByIdentifier(''));
        self::assertNull($this->subject->getScopeEntityByIdentifier('openid'));
    }

    #[Test]
    public function finalizeScopesAlwaysReturnsMcpScope(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);

        // Even when empty scopes are passed, mcp scope is returned.
        $result = $this->subject->finalizeScopes([], 'authorization_code', $client);

        self::assertCount(1, $result);
        self::assertSame('mcp', $result[0]->getIdentifier());
    }

    #[Test]
    public function finalizeScopesIgnoresRequestedScopes(): void
    {
        $client = $this->createMock(ClientEntityInterface::class);

        // Even when random scopes are passed, only mcp is returned.
        $result = $this->subject->finalizeScopes(
            [new OAuthScope('mcp'), new OAuthScope('admin')],
            'refresh_token',
            $client,
            'user-123',
        );

        self::assertCount(1, $result);
        self::assertSame('mcp', $result[0]->getIdentifier());
    }

    #[Test]
    public function mcpScopeSerializesToString(): void
    {
        $scope = $this->subject->getScopeEntityByIdentifier('mcp');

        self::assertSame('"mcp"', json_encode($scope));
    }
}
