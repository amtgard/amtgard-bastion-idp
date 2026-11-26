<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OidcUserInfoController;
use Amtgard\IdP\Models\CompositeAuthorizationJwtValidator;
use Amtgard\IdP\Models\Oidc\CompositeBearerTokenValidator;
use Amtgard\IdP\Models\Oidc\Es256BearerTokenValidator;
use Amtgard\IdP\Models\Oidc\Rs256BearerTokenValidator;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OidcFixtureKeys;
use Amtgard\IdP\Tests\Support\OidcTokenExchangeHarness;
use Firebase\JWT\JWT as FirebaseJwt;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class OidcUserInfoEndpointTest extends TestCase
{
    public function testAccessTokenWithOpenidAndEmailReturnsSubAndEmail(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('openid email');
        $this->assertArrayHasKey('access_token', $tokens);

        $response = $this->userinfo('GET', $tokens['access_token'], $this->harnessUser());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(
            [
                'sub' => OidcTokenExchangeHarness::USER_ID,
                'email' => 'ada@example.com',
            ],
            $this->json($response)
        );
    }

    public function testPostFormAccessTokenWithOpenidSucceeds(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('openid profile email');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/oauth/userinfo')
            ->withParsedBody(['access_token' => $tokens['access_token']]);

        $response = $this->controller($this->harnessUser())->userinfo(
            $request,
            (new ResponseFactory())->createResponse()
        );

        $body = $this->json($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(OidcTokenExchangeHarness::USER_ID, $body['sub']);
        $this->assertSame('ada@example.com', $body['email']);
        $this->assertSame('Ada Lovelace', $body['name']);
        $this->assertSame('ada', $body['preferred_username']);
        $this->assertArrayNotHasKey('aud', $body);
        $this->assertArrayNotHasKey('iss', $body);
        $this->assertArrayNotHasKey('exp', $body);
        $this->assertArrayNotHasKey('nonce', $body);
    }

    public function testAccessTokenWithoutOpenidReturns403(): void
    {
        $tokens = OidcTokenExchangeHarness::exchange('profile email');
        $response = $this->userinfo('GET', $tokens['access_token'], $this->harnessUser());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'insufficient_scope'], $this->json($response));
    }

    public function testAuthorizationJwtIsRejectedWith401(): void
    {
        FirebaseJwtTestFactory::ensureKeys();
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient(
            OidcTokenExchangeHarness::USER_ID,
            OidcTokenExchangeHarness::CLIENT_ID,
            null,
            '{"Statement":[]}'
        );

        $response = $this->userinfo('GET', $jwt, $this->harnessUser());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'invalid_token'], $this->json($response));
    }

    /**
     * M6: the userinfo endpoint validates an ES256 access token through
     * the composite (ES256 design §5.3/§8 M6). The RS256 half is covered
     * by the harness-token tests above — the harness mints RS256.
     */
    public function testEs256AccessTokenWithOpenidAndEmailReturnsSubAndEmail(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->es256AccessToken(['openid', 'email']),
            $this->harnessUser()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(
            [
                'sub' => OidcTokenExchangeHarness::USER_ID,
                'email' => 'ada@example.com',
            ],
            $this->json($response)
        );
    }

    public function testEs256AccessTokenWithoutOpenidReturns403(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->es256AccessToken(['profile', 'email']),
            $this->harnessUser()
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bearer error="insufficient_scope"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'insufficient_scope'], $this->json($response));
    }

    /**
     * M6: failure cases keep the endpoint's HTTP behaviour identical to
     * the League validator's (401 invalid_token) for both algorithms.
     */
    public function testExpiredEs256AccessTokenIsRejectedWith401(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->es256AccessToken(['openid', 'email'], -60),
            $this->harnessUser()
        );

        $this->assertUnauthorized($response);
    }

    public function testRevokedEs256AccessTokenIsRejectedWith401(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/oauth/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $this->es256AccessToken(['openid', 'email']));

        $response = $this->controller($this->harnessUser(), true)->userinfo(
            $request,
            (new ResponseFactory())->createResponse()
        );

        $this->assertUnauthorized($response);
    }

    public function testUnknownKidEs256AccessTokenIsRejectedWith401(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->es256AccessToken(['openid', 'email'], 3600, 'unknown-kid'),
            $this->harnessUser()
        );

        $this->assertUnauthorized($response);
    }

    public function testNoneAccessTokenIsRejectedWith401(): void
    {
        $response = $this->userinfo('GET', $this->noneToken(), $this->harnessUser());

        $this->assertUnauthorized($response);
    }

    public function testWrongKeyEs256AccessTokenIsRejectedWith401(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->unknownKeyEs256Token(),
            $this->harnessUser()
        );

        $this->assertUnauthorized($response);
    }

    public function testExpiredRs256AccessTokenIsRejectedWith401(): void
    {
        $response = $this->userinfo(
            'GET',
            $this->rs256FixtureAccessToken(-60),
            $this->harnessUser()
        );

        $this->assertUnauthorized($response);
    }

    private function assertUnauthorized(\Psr\Http\Message\ResponseInterface $response): void
    {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(['error' => 'invalid_token'], $this->json($response));
    }

    private function userinfo(string $method, string $token, UserEntity $user): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/oauth/userinfo')
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $this->controller($user)->userinfo($request, (new ResponseFactory())->createResponse());
    }

    private function controller(UserEntity $user, bool $revoked = false): OidcUserInfoController
    {
        $tokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $tokens->method('isAccessTokenRevoked')->willReturn($revoked);

        $users = $this->createMock(UserRepository::class);
        $users->method('findUserByUserId')
            ->with(OidcTokenExchangeHarness::USER_ID)
            ->willReturn($user);

        FirebaseJwtTestFactory::ensureKeys();

        return new OidcUserInfoController(
            new ResourceServer(
                $tokens,
                new CryptKey(OidcFixtureKeys::publicKeyPath(), null, false),
                // M6: the endpoint validates through the composite, ES256
                // current first, RS256 retiring second, with the harness
                // fixture keys — the same shape the container factory
                // builds from the environment.
                new CompositeBearerTokenValidator(
                    new Es256BearerTokenValidator($tokens, OidcFixtureKeys::publicEcPem()),
                    new Rs256BearerTokenValidator($tokens, OidcFixtureKeys::publicPem()),
                )
            ),
            $users,
            CompositeAuthorizationJwtValidator::fromEnvironment()
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function es256AccessToken(array $scopes, int $ttlSeconds = 3600, ?string $kid = null): string
    {
        $now = time();
        $claims = [
            'aud' => OidcTokenExchangeHarness::CLIENT_ID,
            'jti' => 'jti-access-token',
            'sub' => OidcTokenExchangeHarness::USER_ID,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => $scopes,
        ];

        if ($kid !== null) {
            return FirebaseJwt::encode($claims, OidcFixtureKeys::privateEcPem(), 'ES256', $kid);
        }

        return FirebaseJwtTestFactory::encodeEs256($claims);
    }

    /**
     * RS256 access token signed with the OIDC RSA fixture key (the key
     * the harness mints with): validates against the fixture public key.
     */
    private function rs256FixtureAccessToken(int $ttlSeconds = 3600): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'aud' => OidcTokenExchangeHarness::CLIENT_ID,
            'jti' => 'jti-access-token',
            'sub' => OidcTokenExchangeHarness::USER_ID,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'scopes' => ['openid', 'email'],
        ], OidcFixtureKeys::privatePem(), 'RS256');
    }

    private function unknownKeyEs256Token(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $now = time();

        return FirebaseJwt::encode([
            'aud' => OidcTokenExchangeHarness::CLIENT_ID,
            'jti' => 'jti-access-token',
            'sub' => OidcTokenExchangeHarness::USER_ID,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'scopes' => ['openid', 'email'],
        ], $pem, 'ES256');
    }

    private function noneToken(): string
    {
        $now = time();

        return $this->base64Url((string) json_encode(['alg' => 'none', 'typ' => 'JWT']))
            . '.' . $this->base64Url((string) json_encode([
                'aud' => OidcTokenExchangeHarness::CLIENT_ID,
                'jti' => 'jti-access-token',
                'sub' => OidcTokenExchangeHarness::USER_ID,
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + 3600,
                'scopes' => ['openid', 'email'],
            ]))
            . '.';
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function harnessUser(): UserEntity
    {
        return new class extends UserEntity {
            public function getUserId(): string
            {
                return OidcTokenExchangeHarness::USER_ID;
            }

            public function getEmail(): string
            {
                return 'ada@example.com';
            }

            public function getFirstName(): string
            {
                return 'Ada';
            }

            public function getLastName(): string
            {
                return 'Lovelace';
            }

            public function getUsername(): string
            {
                return 'ada';
            }

            public function getUpdatedAt(): ?\DateTime
            {
                return null;
            }
        };
    }
}
