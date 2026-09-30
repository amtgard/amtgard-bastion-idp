<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode B — LowLatency validate accepts authorization JWT only, not League access tokens. */
final class LowLatencyValidateTest extends IntegTestCase
{
    public function testValidateWithAuthorizationJwtReturnsMinimalIdentity(): void
    {
        $tokens = $this->issuePlayerTokens();
        $http = new IntegHttp($this->integBaseUrl());

        $response = $http->getWithBearerToken('/resources/validate', $tokens['authorizationJwt']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $body['email'] ?? null);
        $this->assertIsString($body['id'] ?? null);
        $this->assertNotSame('', $body['id']);
        $this->assertArrayNotHasKey('ork_profile', $body);
        $this->assertArrayNotHasKey('jwt', $body);
    }

    public function testValidateWithCompactJwtReturns200(): void
    {
        $tokens = $this->issuePlayerTokens();
        $http = new IntegHttp($this->integBaseUrl());

        $response = $http->getWithBearerToken('/resources/validate', $tokens['compactJwt']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testValidateRejectsOAuthAccessTokenBearer(): void
    {
        $tokens = $this->issuePlayerTokens();
        $http = new IntegHttp($this->integBaseUrl());

        $response = $http->getWithBearerToken('/resources/validate', $tokens['accessToken']);
        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testValidateWithoutBearerReturnsUnauthorized(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get('/resources/validate');
        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testUserinfoAcceptsAccessTokenWhileValidateRejectsIt(): void
    {
        $tokens = $this->issuePlayerTokens();
        $http = new IntegHttp($this->integBaseUrl());

        $userinfo = $http->getWithBearerToken('/resources/userinfo', $tokens['accessToken']);
        $this->assertSame(200, $userinfo->getStatusCode(), (string) $userinfo->getBody());

        $validate = $http->getWithBearerToken('/resources/validate', $tokens['accessToken']);
        $this->assertSame(401, $validate->getStatusCode(), (string) $validate->getBody());
    }

    /**
     * @return array{accessToken: string, authorizationJwt: string, compactJwt: string}
     */
    private function issuePlayerTokens(): array
    {
        $baseUrl = $this->integBaseUrl();
        $state = 'integ-validate-' . bin2hex(random_bytes(4));
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
        $this->assertIsString($accessToken);
        $this->assertNotSame('', $accessToken);

        $jwtResponse = $apiHttp->getWithBearerToken('/resources/jwt', $accessToken);
        $this->assertSame(200, $jwtResponse->getStatusCode(), (string) $jwtResponse->getBody());
        /** @var array<string, mixed> $jwtPayload */
        $jwtPayload = json_decode((string) $jwtResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $authorizationJwt = $jwtPayload['jwt'] ?? null;
        $compactJwt = $jwtPayload['compact_jwt'] ?? null;
        $this->assertIsString($authorizationJwt);
        $this->assertNotSame('', $authorizationJwt);
        $this->assertIsString($compactJwt);
        $this->assertNotSame('', $compactJwt);

        return [
            'accessToken' => $accessToken,
            'authorizationJwt' => $authorizationJwt,
            'compactJwt' => $compactJwt,
        ];
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
