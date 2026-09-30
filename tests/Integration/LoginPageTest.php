<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
/** Mode A — session cookie and CSRF field on the login form. */
final class LoginPageTest extends IntegTestCase
{
    public function testLoginPageSetsSessionCookieAndCsrfField(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/auth/login');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertTrue($http->hasSessionCookie(), 'Expected PHPSESSID cookie after GET /auth/login');
    }
}
