<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use Amtgard\IdP\Tests\Integration\Support\IntegPubSubRedis;
use Amtgard\IdP\Utility\Jwt;

/** Mode B — fresh mint must hit integ Redis PVH and not 409 on validate or middleware Bearer. */
final class PvhHappyPathTest extends IntegTestCase
{
    public function testFreshAuthorizationJwtValidateUsesRedisPvhWithout409(): void
    {
        $tokens = $this->issuePlayerTokens();
        $authorizationJwt = $tokens['authorizationJwt'];
        $payload = Jwt::parseJwt($authorizationJwt);
        $this->assertIsArray($payload);

        $userUuid = $payload['sub'] ?? null;
        $aud = $payload['aud'] ?? null;
        $presentedPvh = $payload['pvh'] ?? null;
        $this->assertIsString($userUuid);
        $this->assertNotSame('', $userUuid);
        $this->assertIsString($aud);
        $this->assertNotSame('', $aud);
        $this->assertIsString($presentedPvh);
        $this->assertNotSame('', $presentedPvh);

        $cachedJson = IntegPubSubRedis::pvhRecordJson($userUuid, $aud);
        $this->assertIsString($cachedJson);
        /** @var array<string, mixed> $cached */
        $cached = json_decode($cachedJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($presentedPvh, $cached['pvh'] ?? null, 'Redis current pvh must match minted JWT');

        $http = new IntegHttp($this->integBaseUrl());

        $validate = $http->getWithBearerToken('/resources/validate', $authorizationJwt);
        $this->assertSame(200, $validate->getStatusCode(), (string) $validate->getBody());
        $this->assertStringNotContainsString('stale_token', (string) $validate->getBody());

        $validateAgain = $http->getWithBearerToken('/resources/validate', $authorizationJwt);
        $this->assertSame(200, $validateAgain->getStatusCode(), (string) $validateAgain->getBody());

        $userinfo = $http->getWithBearerToken('/resources/userinfo', $authorizationJwt);
        $this->assertSame(200, $userinfo->getStatusCode(), (string) $userinfo->getBody());
        $this->assertStringNotContainsString('stale_token', (string) $userinfo->getBody());
    }

    /**
     * @return array{accessToken: string, authorizationJwt: string}
     */
    private function issuePlayerTokens(): array
    {
        $baseUrl = $this->integBaseUrl();
        $state = 'integ-pvh-happy-' . bin2hex(random_bytes(4));
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
        $this->assertIsString($authorizationJwt);
        $this->assertNotSame('', $authorizationJwt);

        return [
            'accessToken' => $accessToken,
            'authorizationJwt' => $authorizationJwt,
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
