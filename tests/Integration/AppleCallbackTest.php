<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use PHPUnit\Framework\TestCase;

/** Mode A — Apple Sign In redirect and form_post callback login. */
final class AppleCallbackTest extends TestCase
{
    private const PROFILE_PATH = '/resources/profile';
    private const EXPECTED_EMAIL = IntegFixtures::APPLE_EMAIL;

    public function testAppleStartRedirectsToVendorAndFormPostCallbackLogsInWithCannedEmail(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);

        $http->get('/auth/login');
        $this->assertTrue($http->hasSessionCookie(), 'Expected session before Apple redirect');

        $startResponse = $http->get('/auth/apple');
        $this->assertSame(302, $startResponse->getStatusCode(), (string) $startResponse->getBody());
        $vendorHost = $http->locationHost($startResponse);
        $this->assertNotNull($vendorHost, 'Expected Location host on Apple start redirect');
        $this->assertSame('appleid.apple.com', $vendorHost, 'Apple start must redirect to Apple OAuth host');
        $this->assertNotSame($http->idpHost(), $vendorHost, 'Apple start must not redirect to IDP host');

        $authorizeUrl = $http->redirectLocation($startResponse);
        $this->assertNotNull($authorizeUrl);
        $state = $http->parseQueryParam($authorizeUrl, 'state');

        $userJson = json_encode([
            'email' => self::EXPECTED_EMAIL,
            'name' => [
                'firstName' => 'Integ',
                'lastName' => 'Apple',
            ],
        ], JSON_THROW_ON_ERROR);

        $callbackResponse = $http->postForm('/auth/apple/callback', [
            'code' => 'integ-ok',
            'state' => $state,
            'user' => $userJson,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($callbackResponse, self::PROFILE_PATH),
            'Successful Apple callback must redirect to profile; status=' . $callbackResponse->getStatusCode()
            . ' location=' . $callbackResponse->getHeaderLine('Location'),
        );

        $profileResponse = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileResponse->getStatusCode(), (string) $profileResponse->getBody());

        $userinfoResponse = $http->getBearer('/resources/userinfo');
        $this->assertSame(200, $userinfoResponse->getStatusCode(), (string) $userinfoResponse->getBody());
        /** @var array<string, mixed> $userinfo */
        $userinfo = json_decode((string) $userinfoResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::EXPECTED_EMAIL, $userinfo['email'] ?? null);
    }
}
