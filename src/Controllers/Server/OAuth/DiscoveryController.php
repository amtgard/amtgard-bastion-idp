<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\JwksFactory;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DiscoveryController
{
    private const CACHE_CONTROL = 'public, max-age=3600';

    public function __construct(
        private readonly JwksFactory $jwksFactory,
        private readonly string $appUrl,
    ) {
    }

    #[OA\Get(
        path: '/.well-known/openid-configuration',
        operationId: 'openidConfiguration',
        tags: ['OpenID Connect'],
        summary: 'OpenID Provider discovery',
        description: 'Public metadata. issuer is APP_URL with no trailing slash. response_types_supported is code. code_challenge_methods_supported is S256.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'OpenID Provider configuration',
                headers: [
                    new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'public, max-age=3600')),
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'issuer', type: 'string', example: 'https://idp.amtgard.com'),
                        new OA\Property(property: 'authorization_endpoint', type: 'string'),
                        new OA\Property(property: 'token_endpoint', type: 'string'),
                        new OA\Property(property: 'userinfo_endpoint', type: 'string'),
                        new OA\Property(property: 'jwks_uri', type: 'string'),
                        new OA\Property(property: 'response_types_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'grant_types_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'subject_types_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'id_token_signing_alg_values_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'scopes_supported', type: 'array', items: new OA\Items(type: 'string'), example: ['openid', 'profile', 'email']),
                        new OA\Property(property: 'token_endpoint_auth_methods_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'code_challenge_methods_supported', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'claims_supported', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                )
            ),
        ]
    )]
    public function openidConfiguration(Request $request, Response $response): Response
    {
        $issuer = $this->issuer();

        return $this->cachedJson($response, [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'sub',
                'iss',
                'aud',
                'exp',
                'iat',
                'nonce',
                'email',
                'name',
                'preferred_username',
                'updated_at',
            ],
        ]);
    }

    #[OA\Get(
        path: '/.well-known/jwks.json',
        operationId: 'jwks',
        tags: ['OpenID Connect'],
        summary: 'JSON Web Key Set',
        description: 'Public RSA key used to verify id_token signatures. kid is the RFC 7638 SHA-256 thumbprint. No private key material.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'JWKS',
                headers: [
                    new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'public, max-age=3600')),
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'keys',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'kty', type: 'string', example: 'RSA'),
                                    new OA\Property(property: 'use', type: 'string', example: 'sig'),
                                    new OA\Property(property: 'alg', type: 'string', example: 'RS256'),
                                    new OA\Property(property: 'kid', type: 'string'),
                                    new OA\Property(property: 'n', type: 'string'),
                                    new OA\Property(property: 'e', type: 'string'),
                                ]
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function jwks(Request $request, Response $response): Response
    {
        return $this->cachedJson($response, $this->jwksFactory->document());
    }

    private function issuer(): string
    {
        return rtrim($this->appUrl, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cachedJson(Response $response, array $payload): Response
    {
        return JsonResponseBody::write($response, $payload)
            ->withHeader('Cache-Control', self::CACHE_CONTROL);
    }
}
