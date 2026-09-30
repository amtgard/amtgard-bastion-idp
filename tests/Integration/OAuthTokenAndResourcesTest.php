<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use PHPUnit\Framework\TestCase;

/** Mode A allow + Mode B token exchange and resource Bearer endpoints. */
final class OAuthTokenAndResourcesTest extends TestCase
{
    public function testAuthorizationCodeRefreshAndResourceEndpoints(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $state = 'integ-oauth-resources-' . bin2hex(random_bytes(4));

        $sessionHttp = new IntegHttp($baseUrl);
        $this->loginPlayer($sessionHttp);

        $code = $this->captureAuthorizationCode($sessionHttp, $state);
        $apiHttp = new IntegHttp($baseUrl);

        $tokenResponse = $apiHttp->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET);
        $this->assertSame(200, $tokenResponse->getStatusCode(), (string) $tokenResponse->getBody());
        /** @var array<string, mixed> $tokenPayload */
        $tokenPayload = json_decode((string) $tokenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $accessToken = $tokenPayload['access_token'] ?? null;
        $refreshToken = $tokenPayload['refresh_token'] ?? null;
        $this->assertIsString($accessToken);
        $this->assertNotSame('', $accessToken);
        $this->assertIsString($refreshToken);
        $this->assertNotSame('', $refreshToken);

        $refreshResponse = $apiHttp->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET);
        $this->assertSame(200, $refreshResponse->getStatusCode(), (string) $refreshResponse->getBody());
        /** @var array<string, mixed> $refreshPayload */
        $refreshPayload = json_decode((string) $refreshResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $refreshedAccessToken = $refreshPayload['access_token'] ?? null;
        $this->assertIsString($refreshedAccessToken);
        $this->assertNotSame('', $refreshedAccessToken);

        $jwtResponse = $apiHttp->getWithBearerToken('/resources/jwt', $refreshedAccessToken);
        $this->assertSame(200, $jwtResponse->getStatusCode(), (string) $jwtResponse->getBody());
        /** @var array<string, mixed> $jwtPayload */
        $jwtPayload = json_decode((string) $jwtResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $authorizationJwt = $jwtPayload['jwt'] ?? null;
        $this->assertIsString($authorizationJwt);
        $this->assertNotSame('', $authorizationJwt);

        $userinfoResponse = $apiHttp->getWithBearerToken('/resources/userinfo', $authorizationJwt);
        $this->assertSame(200, $userinfoResponse->getStatusCode(), (string) $userinfoResponse->getBody());
        /** @var array<string, mixed> $userinfo */
        $userinfo = json_decode((string) $userinfoResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $userinfo['email'] ?? null);

        $validateResponse = $apiHttp->getWithBearerToken('/resources/validate', $authorizationJwt);
        $this->assertSame(200, $validateResponse->getStatusCode(), (string) $validateResponse->getBody());

        $authorizationsResponse = $apiHttp->getWithBearerToken('/resources/authorizations', $refreshedAccessToken);
        $this->assertSame(200, $authorizationsResponse->getStatusCode(), (string) $authorizationsResponse->getBody());

        $sessionJwtResponse = $sessionHttp->get('/resources/jwt');
        $this->assertSame(200, $sessionJwtResponse->getStatusCode(), (string) $sessionJwtResponse->getBody());
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

    private function captureAuthorizationCode(IntegHttp $http, string $state): string
    {
        $authorizePath = '/oauth/authorize?' . http_build_query([
            'client_id' => IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);

        $authorize = $http->get($authorizePath);
        if ($http->isRedirectToPath($authorize, '/oauth/approve')) {
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

            $authorize = $http->get('/oauth/authorize');
        }

        $this->assertContains($authorize->getStatusCode(), [301, 302], 'Authorize must redirect to client callback');
        $callbackLocation = $http->redirectLocation($authorize);
        $this->assertNotNull($callbackLocation);
        $this->assertStringStartsWith(IntegFixtures::CONFIDENTIAL_REDIRECT_URI, $callbackLocation);

        $query = [];
        parse_str((string) parse_url($callbackLocation, PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state'] ?? null);
        $code = $query['code'] ?? null;
        $this->assertIsString($code);
        $this->assertNotSame('', $code);

        return $code;
    }
}
