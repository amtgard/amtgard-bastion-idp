<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — ORK connect handoff register tab happy path. */
final class ConnectRegisterTest extends IntegTestCase
{
    private const PROFILE_PATH = '/resources/profile';

    public function testConnectRegisterHappyPathCreatesUserAndLinksOrkProfile(): void
    {
        $http = new IntegHttp($this->integBaseUrl());
        $registerEmail = 'integ-connect-register-' . bin2hex(random_bytes(4)) . '@example.com';
        $mundaneId = IntegFixtures::CONNECT_REGISTER_MUNDANE_ID + random_int(0, 9999);
        $jti = 'integ-connect-reg-jti-' . bin2hex(random_bytes(8));
        $linkToken = IntegFixtures::mintConnectLinkToken(
            $jti,
            $registerEmail,
            $mundaneId,
        );

        $connectPage = $http->get('/auth/connect?link_token=' . urlencode($linkToken));
        $this->assertSame(200, $connectPage->getStatusCode(), (string) $connectPage->getBody());
        $connectHtml = (string) $connectPage->getBody();
        $this->assertStringContainsString('Connect your Amtgard sign-in', $connectHtml);
        $this->assertStringContainsString('form-register', $connectHtml);
        $this->assertStringContainsString($registerEmail, $connectHtml);
        $this->assertStringContainsString('action="/auth/connect/register"', $connectHtml);
        $connectCsrf = $http->parseCsrfToken($connectHtml);

        $registerResponse = $http->postForm('/auth/connect/register', [
            '_csrf_token' => $connectCsrf,
            'link_token' => $linkToken,
            'firstName' => 'Integ',
            'lastName' => 'ConnectReg',
            'email' => $registerEmail,
            'password' => IntegFixtures::PASSWORD,
            'confirmPassword' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(302, $registerResponse->getStatusCode());
        $location = $http->redirectLocation($registerResponse);
        $this->assertNotNull($location);
        $this->assertStringContainsString('idp_link_complete', $location);

        $profile = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profile->getStatusCode(), (string) $profile->getBody());
        $profileHtml = (string) $profile->getBody();
        $this->assertStringContainsString(
            'Mundane ID: ' . $mundaneId,
            $profileHtml,
        );
    }
}
