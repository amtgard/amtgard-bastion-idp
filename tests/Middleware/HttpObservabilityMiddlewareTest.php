<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\HttpObservabilityMiddleware;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpObservabilityMiddlewareTest extends TestCase
{
    private TestHandler $appHandler;

    private TestHandler $securityHandler;

    private IdpLogging $logging;

    protected function setUp(): void
    {
        $this->appHandler = new TestHandler();
        $this->securityHandler = new TestHandler();

        $appLogger = new Logger('app');
        $appLogger->pushHandler($this->appHandler);
        $securityLogger = new Logger('security');
        $securityLogger->pushHandler($this->securityHandler);

        $this->logging = $this->createMock(IdpLogging::class);
        $this->logging->method('app')->willReturn($appLogger);
        $this->logging->method('security')->willReturn($securityLogger);
        $this->logging->method('oauth')->willReturn($appLogger);
    }

    public function testLogsInfoFor200OnAppChannel(): void
    {
        $middleware = new HttpObservabilityMiddleware($this->logging);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->response(200));

        $middleware->process($this->request('/version', 'GET'), $handler);

        $this->assertTrue($this->appHandler->hasInfoRecords());
        $this->assertSame('http.request.completed', $this->appHandler->getRecords()[0]->message);
    }

    public function testLogsErrorOn401OnSecurityChannel(): void
    {
        $middleware = new HttpObservabilityMiddleware($this->logging);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->response(401));

        $middleware->process($this->request('/resources/validate', 'GET'), $handler);

        $this->assertTrue($this->securityHandler->hasErrorRecords());
    }

    private function request(string $path, string $method): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        return $request;
    }

    private function response(int $status): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        return $response;
    }
}
