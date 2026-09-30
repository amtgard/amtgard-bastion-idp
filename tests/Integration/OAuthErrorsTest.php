<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A + B — OAuth authorize and token protocol error responses. */
final class OAuthErrorsTest extends IntegTestCase
{
    public function testAuthorizeWithUnknownClientIdReturnsUnauthorizedOAuthPage(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get($this->authorizePath(
            'integ-oauth-no-such-client',
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'integ-state-unknown-client-' . bin2hex(random_bytes(4)),
        ));

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertOAuthAuthorizeProtocolErrorPage($response);
    }

    public function testAuthorizeWithUntrustedRedirectUriReturnsUnauthorizedOAuthPage(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get($this->authorizePath(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            'http://evil.example/integ/oauth-callback',
            'integ-state-bad-redirect-' . bin2hex(random_bytes(4)),
        ));

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertOAuthAuthorizeProtocolErrorPage($response);
    }

    public function testAuthorizeAfterAllowUsesSessionStateNotTamperedResumeQuery(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $originalState = 'integ-state-session-' . bin2hex(random_bytes(4));
        $tamperedState = 'integ-state-tampered-' . bin2hex(random_bytes(4));
        $this->loginPlayer($http);
        $this->allowAuthorizationForState($http, $originalState);

        $resume = $http->get($this->authorizePath(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            $tamperedState,
        ));
        $this->assertContains($resume->getStatusCode(), [301, 302], 'Approved authorize must redirect to client callback');
        $callbackLocation = $http->redirectLocation($resume);
        $this->assertNotNull($callbackLocation);
        $this->assertStringStartsWith(IntegFixtures::CONFIDENTIAL_REDIRECT_URI, $callbackLocation);

        $query = [];
        parse_str((string) parse_url($callbackLocation, PHP_URL_QUERY), $query);
        $this->assertSame($originalState, $query['state'] ?? null);
        $this->assertNotSame($tamperedState, $query['state'] ?? null);
        $this->assertNotEmpty($query['code'] ?? null);
    }

    public function testTokenWithWrongClientSecretReturnsUnauthorized(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'integ-not-a-real-authorization-code',
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, 'integ-wrong-client-secret');

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_client', $payload['error'] ?? null);
    }

    public function testTokenWithInvalidAuthorizationCodeReturnsBadRequest(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'integ-not-a-real-authorization-code',
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET);

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('invalid_request', $payload['error'] ?? null);
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

    private function allowAuthorizationForState(IntegHttp $http, string $state): void
    {
        $authorize = $http->get($this->authorizePath(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            $state,
        ));
        $this->assertTrue(
            $http->isRedirectToPath($authorize, '/oauth/approve'),
            'First authorize must redirect to approve; location=' . $authorize->getHeaderLine('Location'),
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
    }

    private function authorizePath(string $clientId, string $redirectUri, string $state): string
    {
        return '/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);
    }

    private function assertOAuthAuthorizeProtocolErrorPage(\Psr\Http\Message\ResponseInterface $response): void
    {
        $html = (string) $response->getBody();
        $this->assertStringContainsString('OAuth Authorization Error', $html);
        $this->assertStringContainsString('Authorization request (/oauth/authorize)', $html);
        $this->assertStringContainsString('OAuth protocol error', $html);
    }
}
