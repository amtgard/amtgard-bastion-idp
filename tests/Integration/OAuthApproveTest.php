<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — OAuth authorize consent: login gate, approve, deny, and allow with code. */
final class OAuthApproveTest extends IntegTestCase
{
    public function testAuthorizeWhileLoggedOutRedirectsToLogin(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $state = 'integ-state-logged-out-' . bin2hex(random_bytes(4));

        $response = $http->get($this->authorizePath($state));
        $this->assertTrue(
            $http->isRedirectToPath($response, '/auth/login'),
            'Logged-out authorize must redirect to login; status=' . $response->getStatusCode()
            . ' location=' . $response->getHeaderLine('Location'),
        );
    }

    public function testLoggedInAuthorizeRedirectsToApprovePage(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $http = $this->newLoggedInPlayerHttp();

        $firstAuthorize = $http->get($this->authorizePath($state));
        $this->assertTrue(
            $http->isRedirectToPath($firstAuthorize, '/oauth/approve'),
            'First authorize for client must redirect to approve; status=' . $firstAuthorize->getStatusCode()
            . ' location=' . $firstAuthorize->getHeaderLine('Location'),
        );
    }

    public function testApprovePageRendersWithCsrfAndCallback(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $http = $this->newLoggedInPlayerHttp();

        $firstAuthorize = $http->get($this->authorizePath($state));
        $approvePage = $http->get($firstAuthorize->getHeaderLine('Location'));
        $this->assertSame(200, $approvePage->getStatusCode(), (string) $approvePage->getBody());
        $approveHtml = (string) $approvePage->getBody();
        $this->assertNotSame('', $http->parseCsrfToken($approveHtml));
        $this->assertNotSame('', $http->parseHiddenField($approveHtml, 'callback'));
    }

    public function testDenyOnApproveRedirectsHome(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $http = $this->newLoggedInPlayerHttp();
        $form = $this->fetchApproveForm($http, $state);

        $denyResponse = $http->postForm('/oauth/approve', [
            '_csrf_token' => $form['csrf'],
            'callback' => $form['callback'],
            'action' => 'deny',
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($denyResponse, '/'),
            'Deny must redirect home; location=' . $denyResponse->getHeaderLine('Location'),
        );
    }

    public function testAuthorizeAfterDenyShowsApproveAgain(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $authorizePath = $this->authorizePath($state);
        $http = $this->newLoggedInPlayerHttp();
        $form = $this->fetchApproveForm($http, $state);

        $http->postForm('/oauth/approve', [
            '_csrf_token' => $form['csrf'],
            'callback' => $form['callback'],
            'action' => 'deny',
        ]);

        $secondAuthorize = $http->get($authorizePath);
        $this->assertTrue(
            $http->isRedirectToPath($secondAuthorize, '/oauth/approve'),
            'Authorize after deny must show approve again; location=' . $secondAuthorize->getHeaderLine('Location'),
        );
    }

    public function testAllowOnApproveResumesAuthorizeFlow(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $http = $this->newLoggedInPlayerHttp();
        $this->denyOnceThenReachApproveAgain($http, $state);
        $form = $this->fetchApproveForm($http, $state);

        $allowResponse = $http->postForm('/oauth/approve', [
            '_csrf_token' => $form['csrf'],
            'callback' => $form['callback'],
            'action' => 'allow',
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($allowResponse, '/oauth/authorize'),
            'Allow must resume authorize; location=' . $allowResponse->getHeaderLine('Location'),
        );

        $finalizeAuthorize = $http->get('/oauth/authorize');
        $finalizeStatus = $finalizeAuthorize->getStatusCode();
        $this->assertContains($finalizeStatus, [301, 302], 'Approved authorize must redirect to client');
    }

    public function testAllowCompletesAuthorizationWithCodeAndState(): void
    {
        $state = 'integ-state-' . bin2hex(random_bytes(4));
        $http = $this->newLoggedInPlayerHttp();
        $this->denyOnceThenReachApproveAgain($http, $state);
        $form = $this->fetchApproveForm($http, $state);

        $http->postForm('/oauth/approve', [
            '_csrf_token' => $form['csrf'],
            'callback' => $form['callback'],
            'action' => 'allow',
        ]);

        $finalizeAuthorize = $http->get('/oauth/authorize');
        $callbackLocation = $http->redirectLocation($finalizeAuthorize);
        $this->assertNotNull($callbackLocation);
        $this->assertStringStartsWith(IntegFixtures::CONFIDENTIAL_REDIRECT_URI, $callbackLocation);

        $query = [];
        parse_str((string) parse_url($callbackLocation, PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state'] ?? null, 'Callback must echo authorize state');
        $this->assertNotEmpty($query['code'] ?? null, 'Callback must include authorization code');
    }

    private function newLoggedInPlayerHttp(): IntegHttp
    {
        $http = new IntegHttp($this->integBaseUrl());
        $this->loginPlayer($http);

        return $http;
    }

    /**
     * @return array{csrf: string, callback: string}
     */
    private function fetchApproveForm(IntegHttp $http, string $state): array
    {
        $authorize = $http->get($this->authorizePath($state));
        $approvePage = $http->get($authorize->getHeaderLine('Location'));
        $approveHtml = (string) $approvePage->getBody();

        return [
            'csrf' => $http->parseCsrfToken($approveHtml),
            'callback' => $http->parseHiddenField($approveHtml, 'callback'),
        ];
    }

    private function denyOnceThenReachApproveAgain(IntegHttp $http, string $state): void
    {
        $authorizePath = $this->authorizePath($state);
        $form = $this->fetchApproveForm($http, $state);
        $http->postForm('/oauth/approve', [
            '_csrf_token' => $form['csrf'],
            'callback' => $form['callback'],
            'action' => 'deny',
        ]);
        $http->get($authorizePath);
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

    private function authorizePath(string $state): string
    {
        $params = http_build_query([
            'client_id' => IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);

        return '/oauth/authorize?' . $params;
    }
}
