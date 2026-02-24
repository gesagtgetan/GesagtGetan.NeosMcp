<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\OAuth\Controller;

use GesagtGetan\NeosMcp\OAuth\Service\OAuthServerFactory;
use GuzzleHttp\Psr7\Response;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;

/**
 * Discovery endpoints per RFC 9728 (Protected Resource Metadata)
 * and RFC 8414 (Authorization Server Metadata).
 */
class OAuthMetadataController extends ActionController
{
    /** @phpstan-var array<string> */
    protected $supportedMediaTypes = ['application/json']; // @phpstan-ignore property.phpDocType

    #[Flow\Inject]
    protected OAuthServerFactory $oauthServerFactory;

    public function protectedResourceAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $issuer = $this->oauthServerFactory->getIssuer();

        return $this->jsonResponse(200, [
            'resource' => $issuer . '/neos/mcp',
            'authorization_servers' => [$issuer],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServerAction(): ResponseInterface
    {
        if (!$this->oauthServerFactory->isEnabled()) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        $issuer = $this->oauthServerFactory->getIssuer();

        return $this->jsonResponse(200, [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/neos/mcp',
            'token_endpoint' => $issuer . '/oauth/token',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    /** @param array<mixed> $data */
    private function jsonResponse(int $statusCode, array $data): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Access-Control-Allow-Origin' => '*'],
            body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
