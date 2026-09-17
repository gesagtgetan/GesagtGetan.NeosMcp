<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Controller\OAuthTokenController;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GesagtGetan\NeosMcp\Tests\Unit\AbstractUnitTest;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Neos\Flow\Mvc\ActionRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

class OAuthTokenControllerTest extends AbstractUnitTest
{
    private OAuthTokenController $subject;
    private OAuthServerFactory&Stub $oauthServerFactory;
    private AuthorizationServer&Stub $authorizationServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new OAuthTokenController();
        $this->oauthServerFactory = self::createStub(OAuthServerFactory::class);
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);

        $this->authorizationServer = self::createStub(AuthorizationServer::class);
        $this->oauthServerFactory->method('createAuthorizationServer')->willReturn($this->authorizationServer);

        $this->inject($this->subject, 'oauthServerFactory', $this->oauthServerFactory);
        $this->inject($this->subject, 'logger', new NullLogger());
    }

    #[Test]
    public function tokenReturns503WhenDisabled(): void
    {
        $factory = self::createStub(OAuthServerFactory::class);
        $factory->method('isEnabled')->willReturn(false);
        $this->inject($this->subject, 'oauthServerFactory', $factory);
        $this->injectRequest('grant_type=authorization_code&code=test');

        $response = $this->subject->tokenAction();

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
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

    #[Test]
    public function tokenReturnsLeagueErrorAsRfc6749JsonResponse(): void
    {
        $this->authorizationServer->method('respondToAccessTokenRequest')
            ->willThrowException(OAuthServerException::invalidGrant('Invalid auth code'));

        $this->injectRequest('grant_type=authorization_code&code=expired');

        $response = $this->subject->tokenAction();

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_grant', $body['error']);
        self::assertSame('Invalid auth code', $body['hint']);
    }

    private function injectRequest(string $body): void
    {
        $httpRequest = new ServerRequest('POST', 'http://localhost/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);
        $actionRequest = self::createStub(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);
        $this->inject($this->subject, 'request', $actionRequest);
    }
}
