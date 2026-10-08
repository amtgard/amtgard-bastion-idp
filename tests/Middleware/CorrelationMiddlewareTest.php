<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\CorrelationMiddleware;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorrelationMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLogContext::reset();
        parent::tearDown();
    }

    public function testUsesClientRequestIdWhenValid(): void
    {
        $middleware = new CorrelationMiddleware();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('X-Request-Id')->willReturn('client-req-12345678');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($response) {
            $this->assertSame('client-req-12345678', RequestLogContext::id());

            return $response;
        });

        $middleware->process($request, $handler);
    }

    public function testGeneratesRequestIdWhenHeaderMissing(): void
    {
        $middleware = new CorrelationMiddleware();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        $seenId = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use (&$seenId, $response) {
            $seenId = RequestLogContext::id();

            return $response;
        });

        $middleware->process($request, $handler);
        $this->assertNotNull($seenId);
        $this->assertSame(16, strlen((string) $seenId));
    }
}
