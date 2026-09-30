<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers\OAuth;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use PHPUnit\Framework\TestCase;

final class OAuthSessionAuthRequestStoreTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
    }

    public function testIsApprovedRequiresTrueSessionFlag(): void
    {
        $store = new OAuthSessionAuthRequestStore();

        $this->assertFalse($store->isApproved());

        $_SESSION['approved'] = false;
        $this->assertFalse($store->isApproved());

        $store->markApproved();
        $this->assertTrue($store->isApproved());
    }
}
