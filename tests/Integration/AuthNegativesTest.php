<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — CSRF and session-auth gate negatives on browser-facing routes. */
final class AuthNegativesTest extends IntegTestCase
{
    private const PROFILE_PATH = '/resources/profile';

    public function testLoginPostWithoutCsrfTokenIsForbidden(): void
    {
        $http = $this->newHttp();
        $loginPage = $http->get('/auth/login?expand=1');
        $this->assertSame(200, $loginPage->getStatusCode(), (string) $loginPage->getBody());

        $response = $http->postForm('/auth/login', [
            'email' => IntegFixtures::PLAYER_EMAIL,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse(
            $http->isRedirectToPath($response, self::PROFILE_PATH),
            'Login without CSRF must not establish a session redirect to profile',
        );
    }

    public function testLoginPostWithInvalidCsrfTokenIsForbidden(): void
    {
        $http = $this->newHttp();
        $http->get('/auth/login?expand=1');

        $response = $http->postForm('/auth/login', [
            '_csrf_token' => 'integ-invalid-csrf-token',
            'email' => IntegFixtures::PLAYER_EMAIL,
            'password' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testRegisterPostWithoutCsrfTokenIsForbidden(): void
    {
        $http = $this->newHttp();
        $registerPage = $http->get('/auth/register?expand=1');
        $this->assertSame(200, $registerPage->getStatusCode(), (string) $registerPage->getBody());

        $email = 'integ-csrf-register-' . bin2hex(random_bytes(4)) . '@example.com';
        $response = $http->postForm('/auth/register', [
            'firstName' => 'Integ',
            'lastName' => 'Csrf',
            'email' => $email,
            'password' => IntegFixtures::PASSWORD,
            'confirmPassword' => IntegFixtures::PASSWORD,
        ]);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse(
            $http->isRedirectToPath($response, self::PROFILE_PATH),
            'Register without CSRF must not redirect to profile',
        );
    }

    public function testLoggedOutProfileGetRedirectsToLogin(): void
    {
        $http = $this->newHttp();
        $response = $http->get(self::PROFILE_PATH);

        $this->assertTrue(
            $http->isRedirectToPath($response, '/auth/login'),
            'Logged-out profile must redirect to login; status=' . $response->getStatusCode()
            . ' location=' . $response->getHeaderLine('Location'),
        );
    }

    public function testProfileGetWithBearerTokenReturnsUnauthorized(): void
    {
        $http = $this->newHttp();
        $response = $http->getWithBearerToken(self::PROFILE_PATH, 'integ-bearer-not-allowed-on-cookie-route');

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse(
            $http->isRedirectToPath($response, '/auth/login'),
            'Bearer on cookie route must not fall through to login redirect',
        );
    }

    private function newHttp(): IntegHttp
    {
        return new IntegHttp($this->integBaseUrl());
    }
}
