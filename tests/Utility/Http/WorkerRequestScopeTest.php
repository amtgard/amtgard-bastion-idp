<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Http;

use Amtgard\IdP\Tests\Support\ResetsPhpSessionState;
use Amtgard\IdP\Utility\Http\WorkerRequestScope;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use PHPUnit\Framework\TestCase;

final class WorkerRequestScopeTest extends TestCase
{
    use ResetsPhpSessionState;

    protected function tearDown(): void
    {
        $this->resetPhpSessionState();
        parent::tearDown();
    }

    public function testFinishClosesSessionAndClearsGlobals(): void
    {
        $_SESSION = ['user_id' => 'test-user'];
        @session_start();

        RequestLogContext::set('req-worker-test');
        RequestLogContext::setIdentity('user-uuid', 'client-id');

        WorkerRequestScope::finish(ob_get_level());

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertFalse(isset($_SESSION));
        $this->assertNull(RequestLogContext::id());
        $this->assertNull(RequestLogContext::userUuid());
    }

    public function testFinishIsSafeWhenNoSessionStarted(): void
    {
        RequestLogContext::set('req-only');

        WorkerRequestScope::finish(ob_get_level());

        $this->assertNull(RequestLogContext::id());
    }

    public function testFinishDrainsNestedOutputBuffersStartedInRequest(): void
    {
        $baseline = ob_get_level();
        ob_start();
        ob_start();
        echo 'nested';

        WorkerRequestScope::finish($baseline);

        $this->assertSame($baseline, ob_get_level());
    }
}
