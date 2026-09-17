<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Service;

use GesagtGetan\NeosMcp\OAuth\Exception\OAuthSetupException;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GesagtGetan\NeosMcp\Tests\Unit\AbstractUnitTest;
use PHPUnit\Framework\Attributes\Test;

class OAuthServerFactoryTest extends AbstractUnitTest
{
    private OAuthServerFactory $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new OAuthServerFactory();
    }

    #[Test]
    public function wwwAuthenticateChallengePointsAtPathSuffixedResourceMetadata(): void
    {
        $this->inject($this->subject, 'settings', ['issuer' => 'https://example.com']);

        self::assertSame(
            'Bearer realm="mcp", resource_metadata="https://example.com/.well-known/oauth-protected-resource/api/mcp"',
            $this->subject->getWwwAuthenticateChallenge(),
        );
    }

    #[Test]
    public function wwwAuthenticateChallengeNamesInvalidTokenWhenATokenWasRejected(): void
    {
        $this->inject($this->subject, 'settings', ['issuer' => 'https://example.com']);

        self::assertSame(
            'Bearer realm="mcp", resource_metadata="https://example.com/.well-known/oauth-protected-resource/api/mcp", error="invalid_token"',
            $this->subject->getWwwAuthenticateChallenge(tokenRejected: true),
        );
    }

    #[Test]
    public function wwwAuthenticateChallengeThrowsWithoutIssuer(): void
    {
        $this->inject($this->subject, 'settings', []);

        $this->expectException(OAuthSetupException::class);
        $this->expectExceptionCode(1740000001);

        $this->subject->getWwwAuthenticateChallenge();
    }
}
