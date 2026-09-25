<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use PHPUnit\Framework\TestCase;

class OAuthSessionAuthRequestStoreTest extends TestCase
{
    private OAuthSessionAuthRequestStore $store;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        $this->store = new OAuthSessionAuthRequestStore();
    }

    public function testNonceRoundTripAndClear(): void
    {
        $this->assertFalse($this->store->hasNonce());
        $this->assertNull($this->store->nonce());

        $this->store->storeNonce('rp-nonce');

        $this->assertTrue($this->store->hasNonce());
        $this->assertSame('rp-nonce', $this->store->nonce());

        $this->store->clearNonce();

        $this->assertFalse($this->store->hasNonce());
        $this->assertNull($this->store->nonce());
    }

    public function testNonceReturnsNullWhenSessionValueIsNotAString(): void
    {
        $_SESSION['nonce'] = ['not-a-string'];

        $this->assertTrue($this->store->hasNonce());
        $this->assertNull($this->store->nonce());
    }

    public function testClearNonceIsIdempotentWhenMissing(): void
    {
        $this->store->clearNonce();

        $this->assertFalse($this->store->hasNonce());
    }

    public function testClearAuthorizationStateAlsoClearsNonce(): void
    {
        $this->store->storeNonce('rp-nonce');
        $_SESSION['authRequest'] = 'serialized';
        $_SESSION['approved'] = true;

        $this->store->clearAuthorizationState();

        $this->assertArrayNotHasKey('authRequest', $_SESSION);
        $this->assertArrayNotHasKey('approved', $_SESSION);
        $this->assertArrayNotHasKey('nonce', $_SESSION);
    }

    public function testPromptRoundTripAndClear(): void
    {
        $this->assertFalse($this->store->hasPrompt());
        $this->assertNull($this->store->prompt());

        $this->store->storePrompt('none');

        $this->assertTrue($this->store->hasPrompt());
        $this->assertSame('none', $this->store->prompt());

        $this->store->clearPrompt();

        $this->assertFalse($this->store->hasPrompt());
        $this->assertNull($this->store->prompt());
    }

    public function testPromptReturnsNullWhenSessionValueIsNotAString(): void
    {
        $_SESSION['prompt'] = ['not-a-string'];

        $this->assertTrue($this->store->hasPrompt());
        $this->assertNull($this->store->prompt());
    }

    public function testClearPromptIsIdempotentWhenMissing(): void
    {
        $this->store->clearPrompt();

        $this->assertFalse($this->store->hasPrompt());
    }

    public function testClearAuthorizationStateAlsoClearsPrompt(): void
    {
        $this->store->storePrompt('none');
        $_SESSION['authRequest'] = 'serialized';
        $_SESSION['approved'] = true;

        $this->store->clearAuthorizationState();

        $this->assertArrayNotHasKey('authRequest', $_SESSION);
        $this->assertArrayNotHasKey('approved', $_SESSION);
        $this->assertArrayNotHasKey('prompt', $_SESSION);
    }
}
