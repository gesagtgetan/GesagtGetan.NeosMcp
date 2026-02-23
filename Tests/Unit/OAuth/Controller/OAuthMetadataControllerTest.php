<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Controller\OAuthMetadataController;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;

class OAuthMetadataControllerTest extends UnitTestCase
{
    private OAuthMetadataController $subject;
    private OAuthServerFactory&MockObject $oauthServerFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new OAuthMetadataController();
        $this->oauthServerFactory = $this->createMock(OAuthServerFactory::class);
        $this->inject($this->subject, 'oauthServerFactory', $this->oauthServerFactory);
    }

    /**
     * @test
     */
    public function protectedResourceReturns404WhenDisabled(): void
    {
        $this->oauthServerFactory->method('isEnabled')->willReturn(false);

        $response = $this->subject->protectedResourceAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function protectedResourceReturnsCorrectMetadata(): void
    {
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);
        $this->oauthServerFactory->method('getIssuer')->willReturn('https://example.com');

        $response = $this->subject->protectedResourceAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeBody($response);
        self::assertSame('https://example.com/neos/mcp', $body['resource']);
        self::assertSame(['https://example.com'], $body['authorization_servers']);
        self::assertSame(['header'], $body['bearer_methods_supported']);
    }

    /**
     * @test
     */
    public function authorizationServerReturns404WhenDisabled(): void
    {
        $this->oauthServerFactory->method('isEnabled')->willReturn(false);

        $response = $this->subject->authorizationServerAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function authorizationServerReturnsCorrectMetadata(): void
    {
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);
        $this->oauthServerFactory->method('getIssuer')->willReturn('https://example.com');

        $response = $this->subject->authorizationServerAction();

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeBody($response);
        self::assertSame('https://example.com', $body['issuer']);
        self::assertSame('https://example.com/neos/mcp', $body['authorization_endpoint']);
        self::assertSame('https://example.com/oauth/token', $body['token_endpoint']);
        self::assertArrayNotHasKey('registration_endpoint', $body);
        self::assertSame(['code'], $body['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $body['grant_types_supported']);
        self::assertSame(['S256'], $body['code_challenge_methods_supported']);
        self::assertSame(['client_secret_post'], $body['token_endpoint_auth_methods_supported']);
        self::assertSame(['mcp'], $body['scopes_supported']);
    }

    /** @return array<string, mixed> */
    private function decodeBody(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
