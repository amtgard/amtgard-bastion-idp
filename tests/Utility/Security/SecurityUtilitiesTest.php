<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Security;

use Amtgard\IdP\Utility\Security\CsrfTokenManager;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthCallbackValidator;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Amtgard\IdP\Utility\Security\ScriptAlertResponse;
use PHPUnit\Framework\TestCase;

class SecurityUtilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        $_SERVER['HTTP_HOST'] = 'idp.example.com';
    }

    public function testOAuth2StateManagerStoresAndValidates(): void
    {
        OAuth2StateManager::store('expected-state');

        $this->assertTrue(OAuth2StateManager::validateAndConsume('expected-state'));
        $this->assertFalse(OAuth2StateManager::validateAndConsume('expected-state'));
    }

    public function testOAuth2StateManagerRejectsInvalidState(): void
    {
        OAuth2StateManager::store('expected-state');

        $this->assertFalse(OAuth2StateManager::validateAndConsume('wrong-state'));
        $this->assertArrayNotHasKey('oauth2state', $_SESSION);
    }

    public function testRedirectValidatorAllowsRelativePaths(): void
    {
        $this->assertTrue(RedirectValidator::isSafe('/oauth/authorize?client_id=ork'));
        $this->assertSame('/oauth/authorize', RedirectValidator::sanitize('/oauth/authorize', '/'));
    }

    public function testRedirectValidatorRejectsExternalAndProtocolRelativeUrls(): void
    {
        $this->assertFalse(RedirectValidator::isSafe('https://evil.com/phish'));
        $this->assertFalse(RedirectValidator::isSafe('//evil.com/phish'));
        $this->assertSame('/', RedirectValidator::sanitize('https://evil.com', '/'));
        $this->assertNull(RedirectValidator::sanitizeOrNull('https://evil.com'));
    }

    public function testRedirectValidatorAllowsSameHostAbsoluteUrls(): void
    {
        $this->assertTrue(RedirectValidator::isSafe('https://idp.example.com/resources/profile'));
    }

    public function testScriptAlertResponseEscapesMessage(): void
    {
        $html = ScriptAlertResponse::alertAndRedirect('"><script>alert(1)</script>', '/auth/login');

        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('window.location.href = "/auth/login"', $html);
    }

    public function testOAuthCallbackValidatorReturnsProviderError(): void
    {
        $result = OAuthCallbackValidator::validate(['error' => 'access_denied'], 'Google');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Google authentication failed', $result);
    }

    public function testOAuthCallbackValidatorReturnsInvalidStateError(): void
    {
        OAuth2StateManager::store('valid-state');

        $result = OAuthCallbackValidator::validate(['state' => 'invalid'], 'Discord');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Invalid state parameter', $result);
    }

    public function testOAuthCallbackValidatorReturnsNullOnSuccess(): void
    {
        OAuth2StateManager::store('valid-state');

        $this->assertNull(OAuthCallbackValidator::validate(['state' => 'valid-state'], 'Facebook'));
    }

    public function testCsrfTokenManagerGeneratesAndValidates(): void
    {
        $token = CsrfTokenManager::generate();

        $this->assertTrue(CsrfTokenManager::validate($token));
        $this->assertFalse(CsrfTokenManager::validate('wrong-token'));
    }

    public function testCsrfTokenManagerGetOrCreateReusesSessionToken(): void
    {
        $first = CsrfTokenManager::getOrCreate();
        $second = CsrfTokenManager::getOrCreate();

        $this->assertSame($first, $second);
    }
}
