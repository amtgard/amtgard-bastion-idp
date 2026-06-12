<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\LoginSession;
use PHPUnit\Framework\TestCase;

class LoginSessionTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
    }

    public function testGetLoginIdReturnsNullWhenUnset(): void
    {
        $this->assertNull(LoginSession::getLoginId());
    }

    public function testSetAndGetLoginId(): void
    {
        LoginSession::setLoginId(42);

        $this->assertSame(42, LoginSession::getLoginId());
    }
}
