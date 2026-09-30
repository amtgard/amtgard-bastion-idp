<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — OAuth authorize scope validation and empty-scope consent flow. */
final class OAuthScopesTest extends IntegTestCase
{
    public function testAuthorizeWithUnknownScopeReturnsOAuthProtocolErrorPage(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $response = $http->get($this->authorizePath(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'integ-state-bad-scope-' . bin2hex(random_bytes(4)),
            'integ-not-a-real-scope',
        ));

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertOAuthAuthorizeProtocolErrorPage($response);
        $html = (string) $response->getBody();
        $this->assertStringContainsString('invalid', strtolower($html));
    }

    public function testAuthorizeWithoutScopeParameterCompletesAfterApproval(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $state = 'integ-state-no-scope-' . bin2hex(random_bytes(4));
        $this->loginPlayer($http);

        $authorizePath = $this->authorizePathWithoutScope(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            $state,
        );

        $authorize = $http->get($authorizePath);
        $this->assertTrue(
            $http->isRedirectToPath($authorize, '/oauth/approve'),
            'Authorize without scope must redirect to approve; location=' . $authorize->getHeaderLine('Location'),
        );

        $approveLocation = $authorize->getHeaderLine('Location');
        $this->assertStringContainsString('scope=', $approveLocation);
        $this->assertMatchesRegularExpression('#scope=(?:&|$)#', $approveLocation . '&');

        $code = $this->allowAndCaptureAuthorizationCode($http, $approveLocation);
        $this->assertNotEmpty($code);

        $tokenHttp = new IntegHttp($this->integBaseUrl());
        $tokenResponse = $tokenHttp->postFormWithBasicAuth('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
        ], IntegFixtures::CONFIDENTIAL_CLIENT_ID, IntegFixtures::CONFIDENTIAL_CLIENT_SECRET);
        $this->assertSame(200, $tokenResponse->getStatusCode(), (string) $tokenResponse->getBody());
        /** @var array<string, mixed> $tokenPayload */
        $tokenPayload = json_decode((string) $tokenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('scope', $tokenPayload);
    }

    public function testAuthorizeWithEmptyScopeQueryCompletesAfterApproval(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $state = 'integ-state-empty-scope-' . bin2hex(random_bytes(4));
        $this->loginPlayer($http);

        $authorizePath = $this->authorizePath(
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            $state,
            '',
        );

        $authorize = $http->get($authorizePath);
        $this->assertTrue(
            $http->isRedirectToPath($authorize, '/oauth/approve'),
            'Authorize with empty scope must redirect to approve; location=' . $authorize->getHeaderLine('Location'),
        );

        $code = $this->allowAndCaptureAuthorizationCode($http, $authorize->getHeaderLine('Location'));
        $this->assertNotEmpty($code);
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

    private function allowAndCaptureAuthorizationCode(IntegHttp $http, string $approveLocation): string
    {
        $approvePage = $http->get($approveLocation);
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
        $this->assertContains($finalizeAuthorize->getStatusCode(), [301, 302], 'Approved authorize must redirect to client callback');
        $callbackLocation = $http->redirectLocation($finalizeAuthorize);
        $this->assertNotNull($callbackLocation);
        $this->assertStringStartsWith(IntegFixtures::CONFIDENTIAL_REDIRECT_URI, $callbackLocation);

        $query = [];
        parse_str((string) parse_url($callbackLocation, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }

    /**
     * @param non-empty-string $clientId
     * @param non-empty-string $redirectUri
     * @param non-empty-string $state
     */
    private function authorizePath(string $clientId, string $redirectUri, string $state, string $scope): string
    {
        return '/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ]);
    }

    /**
     * @param non-empty-string $clientId
     * @param non-empty-string $redirectUri
     * @param non-empty-string $state
     */
    private function authorizePathWithoutScope(string $clientId, string $redirectUri, string $state): string
    {
        return '/oauth/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
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
