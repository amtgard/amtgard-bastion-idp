<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegDb;
use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use Amtgard\IdP\Tests\Integration\Support\IntegRecordedMail;
use Amtgard\IdP\Utility\Http\DevIntegHttpClient;

/** Mode A — mailbox possession routes and ORK link-by-code flows. */
final class MailboxPossessionIntegTest extends IntegTestCase
{
    private const PROFILE_PATH = '/resources/profile';

    public function testConnectPossessionCodeLinksExistingPlayer(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $jti = 'integ-possess-jti-' . bin2hex(random_bytes(8));
        $orkChallengeId = 'integ-ork-chal-' . bin2hex(random_bytes(8));
        $linkToken = IntegFixtures::mintPossessionConnectLinkToken(
            $jti,
            IntegFixtures::PLAYER_EMAIL,
            DevIntegHttpClient::INTEG_MUNDANE_ID,
            $orkChallengeId,
        );

        $connectPage = $http->get('/auth/connect?link_token=' . urlencode($linkToken));
        $this->assertSame(200, $connectPage->getStatusCode(), (string) $connectPage->getBody());
        $connectHtml = (string) $connectPage->getBody();
        $this->assertStringContainsString('/auth/connect/code', $connectHtml);
        $challengeId = $http->parseHiddenField($connectHtml, 'challenge_id');
        $connectCsrf = $http->parseCsrfToken($connectHtml);

        $code = IntegRecordedMail::waitForCode(IntegFixtures::PLAYER_EMAIL);

        $submit = $http->postForm('/auth/connect/code', [
            '_csrf_token' => $connectCsrf,
            'link_token' => $linkToken,
            'challenge_id' => $challengeId,
            'code' => $code,
        ]);
        $this->assertSame(302, $submit->getStatusCode());
        $location = $http->redirectLocation($submit);
        $this->assertNotNull($location);
        $this->assertStringContainsString('idp_link_complete', $location);

        $profile = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profile->getStatusCode(), (string) $profile->getBody());
        $this->assertStringContainsString(
            'Mundane ID: ' . DevIntegHttpClient::INTEG_MUNDANE_ID,
            (string) $profile->getBody(),
        );
    }

    public function testLinkOrkCodeMailAndMagicLinkCompletesLink(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $this->loginPlayer($http);

        $profilePage = $http->get(self::PROFILE_PATH);
        $profileHtml = (string) $profilePage->getBody();
        $csrf = $http->parseCsrfToken($profileHtml);

        $mailResponse = $http->postForm('/resources/profile/link-ork-code-mail', [
            '_csrf_token' => $csrf,
            'username' => DevIntegHttpClient::INTEG_ORK_USERNAME,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($mailResponse, self::PROFILE_PATH),
            'link-ork-code-mail redirect; location=' . $mailResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=ork_code_sent', $mailResponse->getHeaderLine('Location'));

        $delivery = IntegRecordedMail::lastDeliveryForEmail(DevIntegHttpClient::INTEG_ORK_MAILBOX_EMAIL);
        $this->assertNotNull($delivery);
        $magicPath = IntegRecordedMail::extractMagicLinkPath($delivery['text'], $this->integBaseUrl());
        $this->assertNotNull($magicPath);

        $magicResponse = $http->get($magicPath);
        $this->assertTrue(
            $http->isRedirectToPath($magicResponse, self::PROFILE_PATH),
            'magic link redirect; location=' . $magicResponse->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=linked', $magicResponse->getHeaderLine('Location'));

        $linkedProfile = $http->get(self::PROFILE_PATH . '?success=linked');
        $linkedHtml = (string) $linkedProfile->getBody();
        $this->assertStringContainsString(
            'Mundane ID: ' . DevIntegHttpClient::INTEG_MUNDANE_ID,
            $linkedHtml,
        );
        $this->assertStringContainsString('Successfully linked ORK account!', $linkedHtml);
    }

    public function testStartLinkOrkCodeAndConnectCompleteFinishesFlow(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $this->loginPlayer($http);

        $profilePage = $http->get(self::PROFILE_PATH);
        $csrf = $http->parseCsrfToken((string) $profilePage->getBody());

        $start = $http->postForm('/resources/profile/link-ork-code', [
            '_csrf_token' => $csrf,
            'username' => DevIntegHttpClient::INTEG_ORK_USERNAME,
        ]);
        $this->assertSame(302, $start->getStatusCode());
        $orkLocation = $http->redirectLocation($start);
        $this->assertNotNull($orkLocation);
        $this->assertStringContainsString('Login/claim_ork', $orkLocation);

        parse_str((string) parse_url($orkLocation, PHP_URL_QUERY), $query);
        $handoffJwt = (string) ($query['t'] ?? '');
        $this->assertNotSame('', $handoffJwt);
        $parts = explode('.', $handoffJwt);
        $this->assertCount(3, $parts);
        /** @var array<string, mixed> $payload */
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $challengeId = (string) ($payload['challenge_id'] ?? '');
        $this->assertNotSame('', $challengeId);

        $idpUserId = IntegDb::idpUserUuidForEmail(IntegFixtures::PLAYER_EMAIL);
        $completionJwt = IntegFixtures::mintFlowACompletionToken(
            $challengeId,
            $idpUserId,
            DevIntegHttpClient::INTEG_MUNDANE_ID,
            'integ-flow-a-complete-' . bin2hex(random_bytes(8)),
        );

        $complete = $http->get('/auth/connect/complete?t=' . urlencode($completionJwt));
        $this->assertTrue(
            $http->isRedirectToPath($complete, self::PROFILE_PATH),
            'connect complete redirect; location=' . $complete->getHeaderLine('Location'),
        );
        $this->assertStringContainsString('success=linked', $complete->getHeaderLine('Location'));
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
            $http->isRedirectToPath($loginResponse, self::PROFILE_PATH),
            'Expected profile redirect after login; location=' . $loginResponse->getHeaderLine('Location'),
        );
    }
}
