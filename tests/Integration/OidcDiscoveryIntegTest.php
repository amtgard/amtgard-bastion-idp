<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/** Mode A+B — OIDC discovery, JWKS, id_token, and /oauth/userinfo. */
final class OidcDiscoveryIntegTest extends IntegTestCase
{
    public function testOpenIdDiscoveryAndJwksArePublic(): void
    {
        $http = new IntegHttp($this->integBaseUrl());

        $discovery = $http->get('/.well-known/openid-configuration');
        $this->assertSame(200, $discovery->getStatusCode(), (string) $discovery->getBody());
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $discovery->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $issuer = (string) ($metadata['issuer'] ?? '');
        $this->assertNotSame('', $issuer);
        $this->assertSame($issuer . '/oauth/userinfo', $metadata['userinfo_endpoint'] ?? null);
        $this->assertSame($issuer . '/.well-known/jwks.json', $metadata['jwks_uri'] ?? null);
        $this->assertContains('openid', $metadata['scopes_supported'] ?? []);

        $jwks = $http->get('/.well-known/jwks.json');
        $this->assertSame(200, $jwks->getStatusCode(), (string) $jwks->getBody());
        /** @var array<string, mixed> $jwksPayload */
        $jwksPayload = json_decode((string) $jwks->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($jwksPayload['keys'] ?? null);
        $this->assertNotEmpty($jwksPayload['keys']);
    }

    public function testAuthorizationCodeWithOpenidReturnsIdTokenAndOauthUserinfo(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $nonce = 'integ-nonce-' . bin2hex(random_bytes(8));
        $state = 'integ-oidc-' . bin2hex(random_bytes(4));
        $this->loginPlayer($http);

        $code = $this->captureAuthorizationCode($http, $state, 'openid email profile', $nonce);

        $tokenHttp = new IntegHttp($this->integBaseUrl());
        $tokenResponse = $tokenHttp->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET);
        $this->assertSame(200, $tokenResponse->getStatusCode(), (string) $tokenResponse->getBody());
        /** @var array<string, mixed> $tokens */
        $tokens = json_decode((string) $tokenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accessToken = $tokens['access_token'] ?? null;
        $idToken = $tokens['id_token'] ?? null;
        $this->assertIsString($accessToken);
        $this->assertIsString($idToken);

        $jwksResponse = $tokenHttp->get('/.well-known/jwks.json');
        /** @var array<string, mixed> $jwks */
        $jwks = json_decode((string) $jwksResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $verified = JWT::decode($idToken, JWK::parseKeySet($jwks));
        $this->assertSame($nonce, $verified->nonce ?? null);

        $discovery = $tokenHttp->get('/.well-known/openid-configuration');
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $discovery->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($metadata['issuer'] ?? '', $verified->iss ?? '');

        $userinfoGet = $tokenHttp->getWithBearerToken('/oauth/userinfo', $accessToken);
        $this->assertSame(200, $userinfoGet->getStatusCode(), (string) $userinfoGet->getBody());
        /** @var array<string, mixed> $userinfo */
        $userinfo = json_decode((string) $userinfoGet->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $userinfo['email'] ?? null);
        $this->assertSame($verified->sub ?? null, $userinfo['sub'] ?? null);

        $userinfoPost = $tokenHttp->postForm('/oauth/userinfo', [
            'access_token' => $accessToken,
        ]);
        $this->assertSame(200, $userinfoPost->getStatusCode(), (string) $userinfoPost->getBody());
    }

    private function loginPlayer(IntegHttp $http): void
    {
        $loginPage = $http->get('/auth/login?expand=1');
        $this->assertSame(200, $loginPage->getStatusCode(), (string) $loginPage->getBody());
        $loginCsrf = $http->parseCsrfToken((string) $loginPage->getBody());
        $loginResponse = $http->postForm('/auth/login', [
            '_csrf_token' => $loginCsrf,
            'email' => IntegFixtures::PLAYER_EMAIL,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($loginResponse, '/resources/profile'),
            'Expected profile redirect after login; location=' . $loginResponse->getHeaderLine('Location'),
        );
    }

    private function captureAuthorizationCode(
        IntegHttp $http,
        string $state,
        string $scope,
        string $nonce,
    ): string {
        $authorizePath = '/oauth/authorize?' . http_build_query([
            'client_id' => IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'nonce' => $nonce,
        ]);

        $authorize = $http->get($authorizePath);
        $this->assertTrue(
            $http->isRedirectToPath($authorize, '/oauth/approve'),
            'Authorize must redirect to approve; location=' . $authorize->getHeaderLine('Location'),
        );

        $approvePage = $http->get($authorize->getHeaderLine('Location'));
        $this->assertSame(200, $approvePage->getStatusCode(), (string) $approvePage->getBody());
        $approveCsrf = $http->parseCsrfToken((string) $approvePage->getBody());
        $callback = $http->parseHiddenField((string) $approvePage->getBody(), 'callback');

        $allow = $http->postForm('/oauth/approve', [
            '_csrf_token' => $approveCsrf,
            'callback' => $callback,
            'action' => 'allow',
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($allow, '/oauth/authorize'),
            'Allow must resume authorize; location=' . $allow->getHeaderLine('Location'),
        );

        $finalizeAuthorize = $http->get('/oauth/authorize');
        $this->assertContains($finalizeAuthorize->getStatusCode(), [301, 302]);
        $callbackLocation = $http->redirectLocation($finalizeAuthorize);
        $this->assertNotNull($callbackLocation);
        $this->assertStringStartsWith(IntegFixtures::CONFIDENTIAL_REDIRECT_URI, $callbackLocation);

        $query = [];
        parse_str((string) parse_url($callbackLocation, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }
}
