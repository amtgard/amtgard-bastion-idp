<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Models\Oidc\OidcClaimFactory;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Jwt;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OidcUserInfoController
{
    private const CACHE_CONTROL = 'no-store';
    private const INSUFFICIENT_SCOPE = 'Bearer error="insufficient_scope"';
    private const INVALID_TOKEN = 'Bearer error="invalid_token"';

    public function __construct(
        private readonly ResourceServer $resourceServer,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[OA\Get(
        path: '/oauth/userinfo',
        operationId: 'oidcUserInfo',
        tags: ['OpenID Connect'],
        summary: 'OpenID UserInfo',
        description: 'Identity claims for the access token from POST /oauth/token. Requires the openid scope. Returns sub plus scoped email, name, preferred_username, and updated_at. Does not return iss, aud, exp, iat, nonce, ORK profile, or IAM policy. An authorization JWT is rejected. POST accepts access_token in the form body when the Authorization header is absent; the header wins when both are present.',
        security: [['oauthAccessToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scoped identity claims',
                headers: [
                    new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'no-store')),
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'sub', type: 'string', description: 'IDP user UUID'),
                        new OA\Property(property: 'email', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'preferred_username', type: 'string'),
                        new OA\Property(property: 'updated_at', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Missing or invalid access token, or an authorization JWT was presented'),
            new OA\Response(response: 403, description: 'Access token does not include the openid scope'),
        ]
    )]
    #[OA\Post(
        path: '/oauth/userinfo',
        operationId: 'oidcUserInfoPost',
        tags: ['OpenID Connect'],
        summary: 'OpenID UserInfo (POST)',
        description: 'Same contract as GET /oauth/userinfo. The access token may be sent as the form field access_token when the Authorization header is absent.',
        security: [['oauthAccessToken' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Scoped identity claims'),
            new OA\Response(response: 401, description: 'Missing or invalid access token, or an authorization JWT was presented'),
            new OA\Response(response: 403, description: 'Access token does not include the openid scope'),
        ]
    )]
    public function userinfo(Request $request, Response $response): Response
    {
        $resolved = $this->resolveAccessTokenRequest($request);
        if ($resolved === null) {
            return $this->unauthorized($response);
        }

        [$token, $authorizedRequest] = $resolved;
        if ($this->isAuthorizationJwt($token)) {
            return $this->unauthorized($response);
        }

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($authorizedRequest);
        } catch (OAuthServerException) {
            return $this->unauthorized($response);
        }

        $scopeIds = $this->scopeIdentifiers($validated->getAttribute('oauth_scopes'));
        if (!in_array('openid', $scopeIds, true)) {
            return JsonResponseBody::writeError($response, 'insufficient_scope', 403)
                ->withHeader('WWW-Authenticate', self::INSUFFICIENT_SCOPE)
                ->withHeader('Cache-Control', self::CACHE_CONTROL);
        }

        $userId = (string) $validated->getAttribute('oauth_user_id');
        $user = $this->userRepository->findUserByUserId($userId);
        if ($user === null) {
            return $this->unauthorized($response);
        }

        return JsonResponseBody::write($response, $this->scopedClaims(OidcClaimFactory::fromUser($user), $scopeIds))
            ->withHeader('Cache-Control', self::CACHE_CONTROL);
    }

    /**
     * @return array{0: string, 1: Request}|null
     */
    private function resolveAccessTokenRequest(Request $request): ?array
    {
        if ($request->hasHeader('Authorization')) {
            $token = Jwt::getBearerJwt($request);
            if ($token === null) {
                return null;
            }

            return [$token, $request];
        }

        $token = $this->formAccessToken($request);
        if ($token === null) {
            return null;
        }

        return [$token, $request->withHeader('Authorization', 'Bearer ' . $token)];
    }

    private function formAccessToken(Request $request): ?string
    {
        if ($request->getMethod() !== 'POST') {
            return null;
        }

        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return null;
        }

        $token = $parsed['access_token'] ?? null;
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token === '' ? null : $token;
    }

    private function isAuthorizationJwt(string $token): bool
    {
        if (Jwt::validateJwtSignature($token) === null) {
            return false;
        }

        $payload = Jwt::parseJwt($token);

        return is_array($payload) && Jwt::isAuthorizationPayload($payload);
    }

    /**
     * @return list<string>
     */
    private function scopeIdentifiers(mixed $scopes): array
    {
        if (!is_array($scopes)) {
            return [];
        }

        $ids = [];
        foreach ($scopes as $scope) {
            if (is_string($scope)) {
                $ids[] = $scope;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int|string> $claims
     * @param list<string> $scopeIds
     *
     * @return array<string, int|string>
     */
    private function scopedClaims(array $claims, array $scopeIds): array
    {
        $body = [];
        if (isset($claims['sub'])) {
            $body['sub'] = $claims['sub'];
        }
        if (in_array('email', $scopeIds, true) && isset($claims['email'])) {
            $body['email'] = $claims['email'];
        }
        if (!in_array('profile', $scopeIds, true)) {
            return $body;
        }
        if (isset($claims['name'])) {
            $body['name'] = $claims['name'];
        }
        if (isset($claims['preferred_username'])) {
            $body['preferred_username'] = $claims['preferred_username'];
        }
        if (isset($claims['updated_at'])) {
            $body['updated_at'] = $claims['updated_at'];
        }

        return $body;
    }

    private function unauthorized(Response $response): Response
    {
        return JsonResponseBody::writeError($response, 'invalid_token', 401)
            ->withHeader('WWW-Authenticate', self::INVALID_TOKEN)
            ->withHeader('Cache-Control', self::CACHE_CONTROL);
    }
}
