<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\LogsController;
use Amtgard\IdP\Services\LogReaderRateLimiter;
use Amtgard\IdP\Services\LogsQueryService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Log\Sqlite\LogSearchPage;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class M10LogReadApiLoggingTest extends TestCase
{
    public function testTraceByRequestIdLogsInfoWhenRowsFound(): void
    {
        $capture = CapturedIdpLogging::create();
        $query = $this->createMock(LogsQueryService::class);
        $query->method('traceByRequestId')->with('req-99')->willReturn(new LogSearchPage([
            ['id' => 1, 'message' => 'ok', 'raw_line' => '{}'],
        ], null));

        $controller = new LogsController($query, new LogReaderRateLimiter(), $capture->idpLogging);
        $response = $controller->traceByRequestId(
            $this->createMock(ServerRequestInterface::class),
            $this->jsonResponse(),
            'req-99',
        );

        $this->assertSame(200, $response->getStatusCode());
        $record = $this->findAppMessage($capture, 'logs trace by request_id served');
        $this->assertSame('req-99', $record->context['request_id'] ?? null);
        $this->assertSame(1, $record->context['row_count'] ?? null);
    }

    public function testTraceByRequestIdLogsErrorWhenMissing(): void
    {
        $capture = CapturedIdpLogging::create();
        $query = $this->createMock(LogsQueryService::class);
        $query->method('traceByRequestId')->willReturn(new LogSearchPage([], null));

        $controller = new LogsController($query, new LogReaderRateLimiter(), $capture->idpLogging);
        $response = $controller->traceByRequestId(
            $this->createMock(ServerRequestInterface::class),
            $this->jsonResponse(),
            'missing-req',
        );

        $this->assertSame(404, $response->getStatusCode());
        $record = $this->findAppMessage($capture, 'logs trace request_id not found');
        $this->assertSame('missing-req', $record->context['request_id'] ?? null);
    }

    public function testRateLimitLogsError(): void
    {
        $capture = CapturedIdpLogging::create();
        $query = $this->createMock(LogsQueryService::class);
        $limiter = $this->createMock(LogReaderRateLimiter::class);
        $limiter->method('allowQuery')->willReturn(false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        $controller = new LogsController($query, $limiter, $capture->idpLogging);
        $response = $controller->traceSearch($request, $this->jsonResponse());

        $this->assertSame(429, $response->getStatusCode());
        $record = $this->findAppMessage($capture, 'logs read rate limited');
        $this->assertSame('resources.logs.trace', $record->context['route'] ?? null);
    }

    private function jsonResponse(): Response
    {
        return new Response();
    }

    private function findAppMessage(CapturedIdpLogging $capture, string $message): \Monolog\LogRecord
    {
        foreach ($capture->handler('app')->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing app log: ' . $message);
    }
}
