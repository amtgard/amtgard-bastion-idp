<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Handlers;

use Amtgard\IdP\Handlers\NotFoundErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Psr7\Factory\ResponseFactory;

class NotFoundErrorHandlerTest extends TestCase
{
    public function testLogsCompactNoticeAndReturns404(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with(
                '404 Not Found',
                [
                    'method'     => 'GET',
                    'path'       => '/images/favicon.ico',
                    'ip'         => '203.0.113.10',
                    'user_agent' => 'TestBrowser/1.0',
                ]
            );

        $handler = $this->createHandler($logger);

        $request = $this->createRequest(
            'GET',
            '/images/favicon.ico',
            '',
            '203.0.113.10',
            'TestBrowser/1.0',
            []
        );
        $exception = new HttpNotFoundException($request);

        $response = $handler($request, $exception, false, true, true);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUsesForwardedForHeaderAndIncludesQuery(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with(
                '404 Not Found',
                [
                    'method'     => 'POST',
                    'path'       => '/missing',
                    'ip'         => '198.51.100.4',
                    'user_agent' => 'Probe/2.0',
                    'query'      => 'foo=bar',
                ]
            );

        $handler = $this->createHandler($logger);

        $request = $this->createRequest(
            'POST',
            '/missing',
            'foo=bar',
            '10.0.0.1',
            'Probe/2.0',
            ['X-Forwarded-For' => ['198.51.100.4, 10.0.0.1']]
        );
        $exception = new HttpNotFoundException($request);

        $handler($request, $exception, false, true, true);
    }

    public function testSkipsLoggingWhenLogErrorsDisabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        $handler = $this->createHandler($logger);
        $request = $this->createRequest('GET', '/missing', '', '127.0.0.1', '', []);
        $exception = new HttpNotFoundException($request);

        $handler($request, $exception, false, false, true);
    }

    private function createHandler(LoggerInterface $logger): NotFoundErrorHandler
    {
        $callableResolver = $this->createMock(CallableResolverInterface::class);
        $callableResolver->method('resolve')->willReturnCallback(
            static fn ($callable) => is_string($callable) ? new $callable() : $callable
        );

        return new NotFoundErrorHandler(
            $callableResolver,
            new ResponseFactory(),
            $logger
        );
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function createRequest(
        string $method,
        string $path,
        string $query,
        string $remoteAddr,
        string $userAgent,
        array $headers,
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);
        $request->method('getHeaderLine')->willReturnCallback(
            static function (string $name) use ($headers, $userAgent): string {
                if ($name === 'User-Agent') {
                    return $userAgent;
                }

                return $headers[$name][0] ?? '';
            }
        );

        return $request;
    }
}
