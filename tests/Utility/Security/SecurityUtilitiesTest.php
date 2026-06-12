<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Security;

use Amtgard\IdP\Utility\Security\CsrfTokenManager;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use Amtgard\IdP\Utility\Security\OAuthCallbackValidator;
use Amtgard\IdP\Utility\Security\RedirectValidator;
use Amtgard\IdP\Tests\Support\ScriptAlertResponseAssert;
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

    // --- OAuth2StateManager ---

    public function testOAuth2StateManagerStoresStateInSession(): void
    {
        OAuth2StateManager::store('my-state');

        $this->assertSame('my-state', $_SESSION['oauth2state']);
    }

    public function testOAuth2StateManagerValidateAndConsumeOnMatch(): void
    {
        OAuth2StateManager::store('expected-state');

        $this->assertTrue(OAuth2StateManager::validateAndConsume('expected-state'));
        $this->assertArrayNotHasKey('oauth2state', $_SESSION);
    }

    public function testOAuth2StateManagerValidateAndConsumeRejectsWrongState(): void
    {
        OAuth2StateManager::store('expected-state');

        $this->assertFalse(OAuth2StateManager::validateAndConsume('wrong-state'));
        $this->assertArrayNotHasKey('oauth2state', $_SESSION);
    }

    public function testOAuth2StateManagerValidateAndConsumeRejectsEmptyState(): void
    {
        OAuth2StateManager::store('expected-state');

        $this->assertFalse(OAuth2StateManager::validateAndConsume(''));
        $this->assertFalse(OAuth2StateManager::validateAndConsume(null));
    }

    public function testOAuth2StateManagerValidateAndConsumeWithoutStoredState(): void
    {
        $this->assertFalse(OAuth2StateManager::validateAndConsume('any-state'));
    }

    // --- RedirectValidator ---

    public function testRedirectValidatorRejectsNullAndEmpty(): void
    {
        $this->assertFalse(RedirectValidator::isSafe(null));
        $this->assertFalse(RedirectValidator::isSafe(''));
    }

    public function testRedirectValidatorAllowsRelativePaths(): void
    {
        $this->assertTrue(RedirectValidator::isSafe('/oauth/authorize?client_id=ork'));
        $this->assertSame('/oauth/authorize', RedirectValidator::sanitize('/oauth/authorize', '/'));
        $this->assertSame('/oauth/authorize', RedirectValidator::sanitizeOrNull('/oauth/authorize'));
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
        $this->assertSame(
            'https://idp.example.com/resources/profile',
            RedirectValidator::sanitizeOrNull('https://idp.example.com/resources/profile')
        );
    }

    public function testRedirectValidatorRejectsSameHostWhenHttpHostMissing(): void
    {
        unset($_SERVER['HTTP_HOST']);

        $this->assertFalse(RedirectValidator::isSafe('https://idp.example.com/profile'));
    }

    public function testRedirectValidatorRejectsInvalidUrlFormat(): void
    {
        $this->assertFalse(RedirectValidator::isSafe('not a valid url'));
        $this->assertFalse(RedirectValidator::isSafe('mailto:user@example.com'));
    }

    public function testRedirectValidatorAllowsHostCaseInsensitively(): void
    {
        $this->assertTrue(RedirectValidator::isSafe('https://IDP.EXAMPLE.COM/profile'));
        $this->assertFalse(RedirectValidator::isSafe('https://other.example.com/profile'));
    }

    // --- ScriptAlertResponse ---

    public function testScriptAlertResponseEscapesMessageAndRedirect(): void
    {
        $html = ScriptAlertResponse::alertAndRedirect('"><script>alert(1)</script>', '/auth/login?x=1&y=2');

        ScriptAlertResponseAssert::assertRedirectTo(
            $html,
            '/auth/login?x=1&y=2&error=%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E'
        );
    }

    public function testScriptAlertResponseOauthProviderError(): void
    {
        $html = ScriptAlertResponse::oauthProviderError('Google', 'access_denied', '/custom/login');

        ScriptAlertResponseAssert::assertRedirectWithError(
            $html,
            'Google authentication failed: access_denied',
            '/custom/login'
        );
    }

    public function testScriptAlertResponseOauthProviderErrorIfPresentReturnsNullWhenNoError(): void
    {
        $this->assertNull(ScriptAlertResponse::oauthProviderErrorIfPresent([], 'Google'));
        $this->assertNull(ScriptAlertResponse::oauthProviderErrorIfPresent(['code' => 'abc'], 'Facebook'));
    }

    public function testScriptAlertResponseOauthProviderErrorIfPresentReturnsErrorHtml(): void
    {
        $html = ScriptAlertResponse::oauthProviderErrorIfPresent(
            ['error' => 'server_error'],
            'Discord',
            '/auth/discord'
        );

        $this->assertNotNull($html);
        ScriptAlertResponseAssert::assertRedirectWithError(
            $html,
            'Discord authentication failed: server_error',
            '/auth/discord'
        );
    }

    public function testScriptAlertResponseOauthProviderErrorIfPresentReturnsNullWhenErrorIsNull(): void
    {
        $this->assertNull(ScriptAlertResponse::oauthProviderErrorIfPresent(['error' => null], 'Google'));
    }

    public function testScriptAlertResponseEscapesQuotesInRedirectUrl(): void
    {
        $html = ScriptAlertResponse::alertAndRedirect('ok', '/path" onclick="alert(1)');

        ScriptAlertResponseAssert::assertRedirectTo(
            $html,
            '/path" onclick="alert(1)?error=ok'
        );
    }

    public function testScriptAlertResponseInvalidOAuthState(): void
    {
        $html = ScriptAlertResponse::invalidOAuthState('/auth/login?policy');

        ScriptAlertResponseAssert::assertRedirectWithError(
            $html,
            'Invalid state parameter',
            '/auth/login?policy'
        );
    }

    // --- OAuthCallbackValidator ---

    public function testOAuthCallbackValidatorReturnsProviderError(): void
    {
        $result = OAuthCallbackValidator::validate(['error' => 'access_denied'], 'Google');

        $this->assertNotNull($result);
        ScriptAlertResponseAssert::assertRedirectWithError(
            $result,
            'Google authentication failed: access_denied',
            '/auth/login'
        );
    }

    public function testOAuthCallbackValidatorReturnsInvalidStateError(): void
    {
        OAuth2StateManager::store('valid-state');

        $result = OAuthCallbackValidator::validate(['state' => 'invalid'], 'Discord');

        $this->assertNotNull($result);
        ScriptAlertResponseAssert::assertRedirectWithError(
            $result,
            'Invalid state parameter',
            '/auth/login'
        );
    }

    public function testOAuthCallbackValidatorReturnsNullOnSuccess(): void
    {
        OAuth2StateManager::store('valid-state');

        $this->assertNull(OAuthCallbackValidator::validate(['state' => 'valid-state'], 'Facebook'));
    }

    public function testOAuthCallbackValidatorUsesCustomRedirectUrl(): void
    {
        OAuth2StateManager::store('valid-state');

        $result = OAuthCallbackValidator::validate(['state' => 'wrong'], 'Google', '/custom');

        ScriptAlertResponseAssert::assertRedirectWithError($result, 'Invalid state parameter', '/custom');
    }

    public function testOAuthCallbackValidatorProviderErrorUsesCustomRedirectUrl(): void
    {
        $result = OAuthCallbackValidator::validate(['error' => 'denied'], 'Google', '/custom');

        ScriptAlertResponseAssert::assertRedirectWithError(
            $result,
            'Google authentication failed: denied',
            '/custom'
        );
    }

    // --- CsrfTokenManager ---

    public function testCsrfTokenFieldConstant(): void
    {
        $this->assertSame('_csrf_token', CsrfTokenManager::TOKEN_FIELD);
    }

    public function testCsrfTokenManagerGenerateStoresAndReturnsToken(): void
    {
        $token = CsrfTokenManager::generate();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function testCsrfTokenManagerGenerateOverwritesExistingToken(): void
    {
        $first = CsrfTokenManager::generate();
        $second = CsrfTokenManager::generate();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $_SESSION['csrf_token']);
    }

    public function testCsrfTokenManagerValidateAcceptsMatchingToken(): void
    {
        $token = CsrfTokenManager::generate();

        $this->assertTrue(CsrfTokenManager::validate($token));
    }

    public function testCsrfTokenManagerValidateRejectsWrongToken(): void
    {
        CsrfTokenManager::generate();

        $this->assertFalse(CsrfTokenManager::validate('wrong-token'));
    }

    public function testCsrfTokenManagerValidateRejectsNullAndEmpty(): void
    {
        CsrfTokenManager::generate();

        $this->assertFalse(CsrfTokenManager::validate(null));
        $this->assertFalse(CsrfTokenManager::validate(''));
    }

    public function testCsrfTokenManagerValidateFailsWhenNoSessionToken(): void
    {
        $this->assertFalse(CsrfTokenManager::validate('some-token'));
    }

    public function testCsrfTokenManagerGetOrCreateReusesSessionToken(): void
    {
        $first = CsrfTokenManager::getOrCreate();
        $second = CsrfTokenManager::getOrCreate();

        $this->assertSame($first, $second);
    }

    public function testCsrfTokenManagerGetOrCreateGeneratesWhenMissing(): void
    {
        $token = CsrfTokenManager::getOrCreate();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $_SESSION['csrf_token']);
    }
}
