<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\CsrfMiddleware;
use Amtgard\IdP\Utility\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;

class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        CsrfTokenManager::generate();
    }

    public function testGetRequestsPassThrough(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->with($request)->willReturn($response);

        $middleware = new CsrfMiddleware($this->createMock(LoggerInterface::class));
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testPostWithValidFormTokenPassesThrough(): void
    {
        $token = CsrfTokenManager::generate();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([CsrfTokenManager::TOKEN_FIELD => $token]);
        $request->method('getHeaderLine')->with('X-CSRF-Token')->willReturn('');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        $middleware = new CsrfMiddleware($this->createMock(LoggerInterface::class));
        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function testPostWithValidHeaderTokenPassesThrough(): void
    {
        $token = CsrfTokenManager::generate();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getHeaderLine')->with('X-CSRF-Token')->willReturn($token);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($this->createMock(ResponseInterface::class));

        $middleware = new CsrfMiddleware($this->createMock(LoggerInterface::class));
        $middleware->process($request, $handler);
    }

    public function testPostWithInvalidTokenThrowsForbidden(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getHeaderLine')->with('X-CSRF-Token')->willReturn('bad-token');
        $request->method('getUri')->willReturn(new \Slim\Psr7\Uri('http', 'localhost', null, '/management/clients'));
        $handler = $this->createMock(RequestHandlerInterface::class);

        $middleware = new CsrfMiddleware($this->createMock(LoggerInterface::class));

        $this->expectException(HttpForbiddenException::class);
        $middleware->process($request, $handler);
    }
}
