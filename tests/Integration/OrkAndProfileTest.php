<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use DevIntegHttpClient;
use Firebase\JWT\JWT;
require_once dirname(__DIR__, 2) . '/config/container/integ/DevIntegHttpClient.php';

/** Mode A — ORK connect JWT handoff, profile link/refresh/unlink, and OAuth revoke. */
final class OrkAndProfileTest extends IntegTestCase
{
    private const PROFILE_PATH = '/resources/profile';

    public function testConnectLinkRefreshUnlinkAndRevoke(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);

        $jti = 'integ-jti-' . bin2hex(random_bytes(8));
        $linkToken = $this->mintConnectToken($jti);

        $connectPage = $http->get('/auth/connect?link_token=' . urlencode($linkToken));
        $this->assertSame(200, $connectPage->getStatusCode(), (string) $connectPage->getBody());
        $connectHtml = (string) $connectPage->getBody();
        $this->assertStringContainsString('Connect your Amtgard sign-in', $connectHtml);
        $connectCsrf = $http->parseCsrfToken($connectHtml);

        $connectLogin = $http->postForm('/auth/connect/login', [
            '_csrf_token' => $connectCsrf,
            'link_token' => $linkToken,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(302, $connectLogin->getStatusCode());
        $connectLocation = $http->redirectLocation($connectLogin);
        $this->assertNotNull($connectLocation);
        $this->assertStringContainsString('idp_link_complete', $connectLocation);

        $profileAfterConnect = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileAfterConnect->getStatusCode(), (string) $profileAfterConnect->getBody());
        $afterConnectHtml = (string) $profileAfterConnect->getBody();
        $this->assertStringContainsString(
            'Mundane ID: ' . DevIntegHttpClient::INTEG_MUNDANE_ID,
            $afterConnectHtml,
        );

        $replayPage = $http->get('/auth/connect?link_token=' . urlencode($linkToken));
        $replayCsrf = $http->parseCsrfToken((string) $replayPage->getBody());
        $replayResponse = $http->postForm('/auth/connect/login', [
            '_csrf_token' => $replayCsrf,
            'link_token' => $linkToken,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(400, $replayResponse->getStatusCode(), (string) $replayResponse->getBody());
        $this->assertStringContainsString('already been used', (string) $replayResponse->getBody());

        $profileForLink = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileForLink->getStatusCode());
        $profileHtml = (string) $profileForLink->getBody();
        $linkCsrf = $http->parseCsrfToken($profileHtml);

        $linkResponse = $http->postForm('/resources/profile/link-ork', [
            '_csrf_token' => $linkCsrf,
            'username' => DevIntegHttpClient::INTEG_ORK_USERNAME,
            'password' => IntegFixtures::ORK_LINK_PASSWORD,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($linkResponse, self::PROFILE_PATH),
            'link-ork must redirect to profile; location=' . $linkResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=linked', $linkResponse->getHeaderLine('Location'));

        $linkedProfile = $http->get(self::PROFILE_PATH . '?success=linked');
        $linkedHtml = (string) $linkedProfile->getBody();
        $this->assertStringContainsString('IntegOrk', $linkedHtml);
        $this->assertStringContainsString(
            'Mundane ID: ' . DevIntegHttpClient::INTEG_MUNDANE_ID,
            $linkedHtml,
        );

        $refreshCsrf = $http->parseCsrfToken($linkedHtml);
        $refreshResponse = $http->postForm('/resources/profile/refresh-ork', [
            '_csrf_token' => $refreshCsrf,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($refreshResponse, self::PROFILE_PATH),
            'refresh-ork must redirect to profile; location=' . $refreshResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=refreshed', $refreshResponse->getHeaderLine('Location'));

        $refreshedProfile = $http->get(self::PROFILE_PATH . '?success=refreshed');
        $unlinkCsrf = $http->parseCsrfToken((string) $refreshedProfile->getBody());
        $unlinkResponse = $http->postForm('/resources/profile/unlink-ork', [
            '_csrf_token' => $unlinkCsrf,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($unlinkResponse, self::PROFILE_PATH),
            'unlink-ork must redirect to profile; location=' . $unlinkResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=unlinked', $unlinkResponse->getHeaderLine('Location'));

        $this->grantConfidentialAuthorization($http);

        $profileForRevoke = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileForRevoke->getStatusCode());
        $revokeHtml = (string) $profileForRevoke->getBody();
        $this->assertStringContainsString(IntegFixtures::CONFIDENTIAL_CLIENT_ID, $revokeHtml);
        $revokeCsrf = $http->parseCsrfToken($revokeHtml);

        $revokeResponse = $http->postForm('/resources/profile/revoke', [
            '_csrf_token' => $revokeCsrf,
            'client_id' => IntegFixtures::CONFIDENTIAL_CLIENT_ID,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($revokeResponse, self::PROFILE_PATH),
            'revoke must redirect to profile; location=' . $revokeResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=revoked', $revokeResponse->getHeaderLine('Location'));
    }

    private function grantConfidentialAuthorization(IntegHttp $http): void
    {
        $state = 'integ-revoke-' . bin2hex(random_bytes(4));
        $authorizePath = '/oauth/authorize?' . http_build_query([
            'client_id' => IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            'redirect_uri' => IntegFixtures::CONFIDENTIAL_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ]);

        $authorize = $http->get($authorizePath);
        if (!$http->isRedirectToPath($authorize, '/oauth/approve')) {
            $callbackLocation = $http->redirectLocation($authorize);
            if (
                is_string($callbackLocation)
                && str_starts_with($callbackLocation, IntegFixtures::CONFIDENTIAL_REDIRECT_URI)
            ) {
                return;
            }
            $this->fail(
                'Expected approve or callback redirect; location=' . $authorize->getHeaderLine('Location'),
            );
        }

        $approvePage = $http->get($authorize->getHeaderLine('Location'));
        $approveCsrf = $http->parseCsrfToken((string) $approvePage->getBody());
        $callback = $http->parseHiddenField((string) $approvePage->getBody(), 'callback');

        $allow = $http->postForm('/oauth/approve', [
            '_csrf_token' => $approveCsrf,
            'callback' => $callback,
            'action' => 'allow',
        ]);
        $this->assertTrue($http->isRedirectToPath($allow, '/oauth/authorize'));

        $finalize = $http->get('/oauth/authorize');
        $this->assertContains($finalize->getStatusCode(), [301, 302]);
    }

    private function mintConnectToken(string $jti): string
    {
        return JWT::encode([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => (string) DevIntegHttpClient::INTEG_MUNDANE_ID,
            'email' => IntegFixtures::PLAYER_EMAIL,
            'jti' => $jti,
            'iat' => time(),
            'exp' => time() + 900,
        ], IntegFixtures::ORK_SHARED_SECRET, 'HS256');
    }
}
