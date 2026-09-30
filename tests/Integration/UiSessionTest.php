<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
use PHPUnit\Framework\TestCase;

/** Mode A — register, logout, login, and failed login in one cookie jar. */
final class UiSessionTest extends TestCase
{
    private const PROFILE_PATH = '/resources/profile';

    public function testRegisterLoginLogoutAndFailedLoginWithOneCookieJar(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);

        $registerEmail = 'integ-register-' . bin2hex(random_bytes(4)) . '@example.com';
        $registerPage = $http->get('/auth/register?expand=1');
        $this->assertSame(200, $registerPage->getStatusCode(), (string) $registerPage->getBody());
        $registerCsrf = $http->parseCsrfToken((string) $registerPage->getBody());

        $registerResponse = $http->postForm('/auth/register', [
            '_csrf_token' => $registerCsrf,
            'firstName' => 'Integ',
            'lastName' => 'Register',
            'email' => $registerEmail,
            'password' => IntegFixtures::PASSWORD,
            'confirmPassword' => IntegFixtures::PASSWORD,
        ]);
        $this->assertTrue(
            $http->isRedirectToPath($registerResponse, self::PROFILE_PATH),
            'Expected 302 to profile after registration; status=' . $registerResponse->getStatusCode()
            . ' location=' . $registerResponse->getHeaderLine('Location'),
        );

        $profileAfterRegister = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileAfterRegister->getStatusCode(), (string) $profileAfterRegister->getBody());

        $logoutResponse = $http->get('/auth/logout');
        $this->assertTrue(
            $http->isRedirectToPath($logoutResponse, '/'),
            'Expected 302 to home after logout; location=' . $logoutResponse->getHeaderLine('Location'),
        );

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
            'Expected 302 to profile after login; status=' . $loginResponse->getStatusCode()
            . ' location=' . $loginResponse->getHeaderLine('Location'),
        );

        $profileAfterLogin = $http->get(self::PROFILE_PATH);
        $this->assertSame(200, $profileAfterLogin->getStatusCode(), (string) $profileAfterLogin->getBody());

        $loginPageAgain = $http->get('/auth/login?expand=1');
        $badLoginCsrf = $http->parseCsrfToken((string) $loginPageAgain->getBody());
        $badLoginResponse = $http->postForm('/auth/login', [
            '_csrf_token' => $badLoginCsrf,
            'email' => IntegFixtures::PLAYER_EMAIL,
            'password' => 'wrong-password-for-integ',
        ]);
        $this->assertFalse(
            $http->isRedirectToPath($badLoginResponse, self::PROFILE_PATH),
            'Wrong password must not redirect to profile; status=' . $badLoginResponse->getStatusCode()
            . ' location=' . $badLoginResponse->getHeaderLine('Location'),
        );
    }
}
