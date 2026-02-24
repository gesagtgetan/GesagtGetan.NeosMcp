<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Controller\OAuthAuthorizeController;
use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Session\SessionInterface;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class OAuthAuthorizeControllerTest extends UnitTestCase
{
    private OAuthAuthorizeController $subject;
    private OAuthServerFactory&MockObject $oauthServerFactory;
    private SecurityContext&MockObject $securityContext;
    private AuthorizationServer&MockObject $authorizationServer;
    private SessionInterface&MockObject $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new OAuthAuthorizeController();
        $this->oauthServerFactory = $this->createMock(OAuthServerFactory::class);
        $this->oauthServerFactory->method('isEnabled')->willReturn(true);
        $this->oauthServerFactory->method('getIssuer')->willReturn('https://example.com');

        $this->authorizationServer = $this->createMock(AuthorizationServer::class);
        $this->oauthServerFactory->method('createAuthorizationServer')->willReturn($this->authorizationServer);

        $this->securityContext = $this->createMock(SecurityContext::class);
        $this->session = $this->createMock(SessionInterface::class);

        $this->inject($this->subject, 'oauthServerFactory', $this->oauthServerFactory);
        $this->inject($this->subject, 'securityContext', $this->securityContext);
        $this->inject($this->subject, 'session', $this->session);
    }

    /**
     * @test
     */
    public function authorizeReturns404WhenDisabled(): void
    {
        $factory = $this->createMock(OAuthServerFactory::class);
        $factory->method('isEnabled')->willReturn(false);
        $this->inject($this->subject, 'oauthServerFactory', $factory);
        $this->injectGetRequest([]);

        $response = $this->subject->authorizeAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function authorizeReturns401WithoutSession(): void
    {
        $this->securityContext->method('getAccount')->willReturn(null);
        $this->injectGetRequest([]);

        $response = $this->subject->authorizeAction();

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('Authentication required', $body['error']);
    }

    /**
     * @test
     */
    public function authorizeShowsConsentScreenWithCsrfTokenAndSecurityHeaders(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->oauthServerFactory->method('getConfiguredClientId')->willReturn('configured-id');
        $this->session->expects(self::once())->method('putData');

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getName')->willReturn('Unknown App');
        $client->method('getIdentifier')->willReturn('abc123');

        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient($client);
        $authRequest->setRedirectUri('https://example.com/callback');
        $authRequest->setState('some-state');

        $this->authorizationServer->method('validateAuthorizationRequest')->willReturn($authRequest);

        $this->injectGetRequest(['response_type' => 'code', 'client_id' => 'abc123']);

        $response = $this->subject->authorizeAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));

        $html = (string) $response->getBody();
        self::assertStringContainsString('Unknown App', $html);
        self::assertStringContainsString('admin@example.com', $html);
        self::assertStringContainsString('Authorize', $html);
        self::assertStringContainsString('Deny', $html);
        self::assertStringContainsString('name="client_id" value="abc123"', $html);
        self::assertStringContainsString('name="csrf_token"', $html);
    }

    /**
     * @test
     */
    public function authorizeAutoGrantsForConfiguredClient(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->oauthServerFactory->method('getConfiguredClientId')->willReturn('configured-id');

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('configured-id');

        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient($client);

        $this->authorizationServer->method('validateAuthorizationRequest')->willReturn($authRequest);
        $this->authorizationServer->method('completeAuthorizationRequest')
            ->willReturnCallback(function (AuthorizationRequest $request) {
                self::assertTrue($request->isAuthorizationApproved());
                self::assertNotNull($request->getUser());
                self::assertSame('admin@example.com', $request->getUser()->getIdentifier());

                return new \GuzzleHttp\Psr7\Response(302, ['Location' => 'https://example.com/callback?code=abc']);
            });

        $this->injectGetRequest(['response_type' => 'code', 'client_id' => 'test']);

        $response = $this->subject->authorizeAction();

        // Auto-granted — should be a redirect, not a consent screen.
        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function grantReturns404WhenDisabled(): void
    {
        $factory = $this->createMock(OAuthServerFactory::class);
        $factory->method('isEnabled')->willReturn(false);
        $this->inject($this->subject, 'oauthServerFactory', $factory);
        $this->injectPostRequest('approve=1');

        $response = $this->subject->grantAction();

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function grantReturns401WithoutSession(): void
    {
        $this->securityContext->method('getAccount')->willReturn(null);
        $this->injectPostRequest('approve=1');

        $response = $this->subject->grantAction();

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function grantRejects403WithoutCsrfToken(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->session->method('getData')->willReturn(null);
        $this->injectPostRequest('approve=1&response_type=code&client_id=test');

        $response = $this->subject->grantAction();

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('Invalid CSRF token', $body['error']);
    }

    /**
     * @test
     */
    public function grantRejects403WithWrongCsrfToken(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->session->method('getData')->willReturn('correct-token');
        $this->injectPostRequest('approve=1&csrf_token=wrong-token&response_type=code&client_id=test');

        $response = $this->subject->grantAction();

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function grantPassesApprovalFlagToLeague(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->session->method('getData')->willReturn('valid-csrf-token');

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getName')->willReturn('Test');

        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient($client);

        $this->authorizationServer->method('validateAuthorizationRequest')->willReturn($authRequest);
        $this->authorizationServer->method('completeAuthorizationRequest')
            ->willReturnCallback(function (AuthorizationRequest $request) {
                self::assertTrue($request->isAuthorizationApproved());

                return new \GuzzleHttp\Psr7\Response(302);
            });

        $this->injectPostRequest('approve=1&csrf_token=valid-csrf-token&response_type=code&client_id=test&redirect_uri=https://example.com/callback');

        $response = $this->subject->grantAction();

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function grantPassesDenialFlagToLeague(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->session->method('getData')->willReturn('valid-csrf-token');

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getName')->willReturn('Test');

        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient($client);

        $this->authorizationServer->method('validateAuthorizationRequest')->willReturn($authRequest);
        $this->authorizationServer->method('completeAuthorizationRequest')
            ->willReturnCallback(function (AuthorizationRequest $request) {
                self::assertFalse($request->isAuthorizationApproved());

                return new \GuzzleHttp\Psr7\Response(302);
            });

        $this->injectPostRequest('approve=0&csrf_token=valid-csrf-token&response_type=code&client_id=test&redirect_uri=https://example.com/callback');

        $response = $this->subject->grantAction();

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function authorizeForwardsLeagueValidationError(): void
    {
        $this->securityContext->method('getAccount')->willReturn($this->createAccount('admin@example.com'));
        $this->authorizationServer->method('validateAuthorizationRequest')
            ->willThrowException(OAuthServerException::invalidClient(new ServerRequest('GET', '/')));

        $this->injectGetRequest(['response_type' => 'code', 'client_id' => 'nonexistent']);

        $response = $this->subject->authorizeAction();

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /** @param array<string, string> $queryParams */
    private function injectGetRequest(array $queryParams): void
    {
        $httpRequest = new ServerRequest('GET', 'http://localhost/api/mcp');
        $httpRequest = $httpRequest->withQueryParams($queryParams);
        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);
        $this->inject($this->subject, 'request', $actionRequest);
    }

    private function injectPostRequest(string $body): void
    {
        $httpRequest = new ServerRequest('POST', 'http://localhost/api/mcp/grant', [], $body);
        $actionRequest = $this->createMock(ActionRequest::class);
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);
        $this->inject($this->subject, 'request', $actionRequest);
    }

    private function createAccount(string $identifier): Account&MockObject
    {
        $account = $this->createMock(Account::class);
        $account->method('getAccountIdentifier')->willReturn($identifier);

        return $account;
    }
}
