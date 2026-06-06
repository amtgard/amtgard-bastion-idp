<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware {

    use Amtgard\IdP\Middleware\SessionMiddleware;
    use Amtgard\IdP\Tests\Support\ResetsPhpSessionState;
    use PHPUnit\Framework\TestCase;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\RequestHandlerInterface;

    class SessionMiddlewareTest extends TestCase
    {
        use ResetsPhpSessionState;

        protected function setUp(): void
        {
            $this->captureSessionIniState();
            $this->resetPhpSessionState();
            unset(
                $_ENV['SESSION_REDIS_HOST'],
                $_ENV['SESSION_REDIS_PORT'],
                $_ENV['SESSION_REDIS_DB'],
                $_ENV['SESSION_REDIS_PREFIX']
            );
        }

        protected function tearDown(): void
        {
            $this->restoreSessionIniState();
            unset($_ENV['SESSION_REDIS_HOST'], $_ENV['SESSION_REDIS_DB']);
        }

        public function testProcessSessionActive(): void
        {
            $GLOBALS['mock_session_active'] = true;

            $middleware = new SessionMiddleware();

            $_SESSION = ['foo' => 'bar'];

            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('withAttribute')
                ->with('session', ['foo' => 'bar'])
                ->willReturnSelf();

            $responseMock = $this->createMock(ResponseInterface::class);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->with($request)->willReturn($responseMock);

            $response = $middleware->process($request, $handler);
            $this->assertSame($responseMock, $response);
        }

        public function testProcessSessionInactive(): void
        {
            $GLOBALS['mock_session_active'] = false;

            $middleware = new SessionMiddleware();

            $_SESSION = ['foo' => 'baz'];

            $request = $this->createMock(ServerRequestInterface::class);
            $request->expects($this->once())
                ->method('withAttribute')
                ->with('session', ['foo' => 'baz'])
                ->willReturnSelf();

            $responseMock = $this->createMock(ResponseInterface::class);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->with($request)->willReturn($responseMock);

            $response = $middleware->process($request, $handler);
            $this->assertSame($responseMock, $response);
        }

        public function testProcessConfiguresSharedRedisSessionsWhenInactive(): void
        {
            $GLOBALS['mock_session_active'] = false;
            $_ENV['SESSION_REDIS_HOST'] = 'amtgard-idp-sessions';
            $_ENV['SESSION_REDIS_DB'] = '1';

            $middleware = new SessionMiddleware();
            $_SESSION = [];

            $request = $this->createMock(ServerRequestInterface::class);
            $request->method('withAttribute')->willReturnSelf();

            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn($this->createMock(ResponseInterface::class));

            $middleware->process($request, $handler);

            $this->assertSame('redis', ini_get('session.save_handler'));
            $this->assertSame(
                'tcp://amtgard-idp-sessions:6379?database=1&prefix=PHPSESS:',
                ini_get('session.save_path')
            );
        }
    }
}

namespace Amtgard\IdP\Middleware {
    function session_status() {
        return ($GLOBALS['mock_session_active'] ?? true) ? \PHP_SESSION_ACTIVE : \PHP_SESSION_NONE;
    }

    function session_start() {
        return true;
    }
}
