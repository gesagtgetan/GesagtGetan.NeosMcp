<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Controller\OAuthTokenController;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class OAuthTokenControllerTest extends UnitTestCase
{
    private OAuthTokenController $subject;
    private OAuthServerFactory&MockObject $oauthServerFactory;
    private AuthorizationServer&MockObject $authorizationServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new OAuthTokenController();
        $this->oauthServerFactory = $this->createMock(OAuthServerFactory::class);
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);

        $this->authorizationServer = $this->createMock(AuthorizationServer::class);
        $this->oauthServerFactory->method('createAuthorizationServer')->willReturn($this->authorizationServer);

        $this->inject($this->subject, 'oauthServerFactory', $this->oauthServerFactory);
    }

    /**
     * @test
     */
    public function tokenReturns404WhenDisabled(): void
    {
        $factory = $this->createMock(OAuthServerFactory::class);
        $factory->method('isEnabled')->willReturn(false);
        $this->inject($this->subject, 'oauthServerFactory', $factory);
        $this->injectRequest('grant_type=authorization_code&code=test');

        $response = $this->subject->tokenAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function tokenDelegatesToLeagueOnSuccess(): void
    {
        // Simulate league's behavior: it writes to the body stream via write(),
        // which advances the pointer to the end. The controller must rewind
        // before returning, otherwise Flow's emitter reads an empty body.
        $leagueResponse = new \GuzzleHttp\Psr7\Response(200);
        $leagueResponse->getBody()->write('{"access_token":"jwt"}');

        $this->authorizationServer->method('respondToAccessTokenRequest')
            ->willReturn($leagueResponse);

        $this->injectRequest('grant_type=authorization_code&code=test');

        $response = $this->subject->tokenAction();

        self::assertSame(200, $response->getStatusCode());
        // Use getContents() (not __toString()) because that's how emitters read
        // the body — from the current stream position, without rewinding.
        self::assertSame('{"access_token":"jwt"}', $response->getBody()->getContents());
    }

    /**
     * @test
     */
    public function tokenReturnsLeagueErrorOnFailure(): void
    {
        $this->authorizationServer->method('respondToAccessTokenRequest')
            ->willThrowException(OAuthServerException::invalidGrant('Invalid auth code'));

        $this->injectRequest('grant_type=authorization_code&code=expired');

        $this->expectException(\GesagtGetan\NeosMcp\OAuth\Exception\OAuthServerException::class);

        $this->subject->tokenAction();
    }

    private function injectRequest(string $body): void
    {
        $httpRequest = new ServerRequest('POST', 'http://localhost/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);
        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);
        $this->inject($this->subject, 'request', $actionRequest);
    }
}
