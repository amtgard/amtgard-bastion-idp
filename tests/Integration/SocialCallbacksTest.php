<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Mode A — Google, Facebook, and Discord OAuth start redirects and callback login. */
final class SocialCallbacksTest extends TestCase
{
    private const PROFILE_PATH = '/resources/profile';

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function socialProviderCases(): iterable
    {
        yield 'google' => [
            '/auth/google',
            '/auth/google/callback',
            'accounts.google.com',
            'integ-google@example.com',
        ];
        yield 'facebook' => [
            '/auth/facebook',
            '/auth/facebook/callback',
            'www.facebook.com',
            'integ-facebook@example.com',
        ];
        yield 'discord' => [
            '/auth/discord',
            '/auth/discord/callback',
            'discord.com',
            'integ-discord@example.com',
        ];
    }

    #[DataProvider('socialProviderCases')]
    public function testSocialStartRedirectsToVendorAndCallbackLogsInWithCannedEmail(
        string $startPath,
        string $callbackPath,
        string $expectedVendorHost,
        string $expectedEmail,
    ): void {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);

        $http->get('/auth/login');
        $this->assertTrue($http->hasSessionCookie(), 'Expected session before social redirect');

        $startResponse = $http->get($startPath);
        $this->assertSame(302, $startResponse->getStatusCode(), (string) $startResponse->getBody());
        $vendorHost = $http->locationHost($startResponse);
        $this->assertNotNull($vendorHost, 'Expected Location host on social start redirect');
        $this->assertSame($expectedVendorHost, $vendorHost, 'Social start must redirect to vendor OAuth host');
        $this->assertNotSame($http->idpHost(), $vendorHost, 'Social start must not redirect to IDP host');

        $authorizeUrl = $http->redirectLocation($startResponse);
        $this->assertNotNull($authorizeUrl);
        $state = $http->parseQueryParam($authorizeUrl, 'state');

        $callbackResponse = $http->get($callbackPath . '?code=integ-ok&state=' . rawurlencode($state));
        $this->assertTrue(
            $http->isRedirectToPath($callbackResponse, self::PROFILE_PATH),
            'Successful social callback must redirect to profile; status=' . $callbackResponse->getStatusCode()
            . ' location=' . $callbackResponse->getHeaderLine('Location'),
        );

        $profileResponse = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileResponse->getStatusCode(), (string) $profileResponse->getBody());

        $userinfoResponse = $http->getBearer('/resources/userinfo');
        $this->assertSame(200, $userinfoResponse->getStatusCode(), (string) $userinfoResponse->getBody());
        /** @var array<string, mixed> $userinfo */
        $userinfo = json_decode((string) $userinfoResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedEmail, $userinfo['email'] ?? null);
    }

    public function testGoogleCallbackWithIntegDenyDoesNotEstablishCannedUser(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);

        $http->get('/auth/login');
        $startResponse = $http->get('/auth/google');
        $this->assertSame(302, $startResponse->getStatusCode());

        $authorizeUrl = $http->redirectLocation($startResponse);
        $this->assertNotNull($authorizeUrl);
        $state = $http->parseQueryParam($authorizeUrl, 'state');

        $denyResponse = $http->get(
            '/auth/google/callback?code=integ-deny&state=' . rawurlencode($state)
        );
        $this->assertNotSame(
            302,
            $denyResponse->getStatusCode(),
            'integ-deny must not complete a profile redirect; location=' . $denyResponse->getHeaderLine('Location'),
        );

        $profileResponse = $http->get(self::PROFILE_PATH);
        $this->assertNotSame(
            200,
            $profileResponse->getStatusCode(),
            'Profile must not be reachable after integ-deny Google callback',
        );
        $this->assertTrue(
            $http->isRedirectToPath($profileResponse, '/auth/login'),
            'Unauthenticated profile must redirect to login; location=' . $profileResponse->getHeaderLine('Location'),
        );

        $jwtResponse = $http->get('/resources/jwt');
        $this->assertSame(401, $jwtResponse->getStatusCode(), 'Session must stay unauthenticated after integ-deny');
    }
}
