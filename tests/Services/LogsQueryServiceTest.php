<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\IdP\Services\LogsQueryService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Log\Sqlite\JsonLogSpoolHandler;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Amtgard\IdP\Utility\Log\Sqlite\LogSpoolDrainer;
use Amtgard\IdP\Utility\Log\Sqlite\MonologJsonLineParser;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogQuery;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogSchema;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogsQueryServiceTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/idp-log-query-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpdir);
    }

    public function testQueryExecutedLogsInfo(): void
    {
        $capture = CapturedIdpLogging::create();
        $service = $this->service($capture);
        $this->seedRequest('req-query-1');

        $page = $service->traceByRequestId('req-query-1');
        $this->assertCount(1, $page->entries);

        $record = $this->findAppMessage($capture, 'logs query executed');
        $this->assertSame('trace', $record->context['stream'] ?? null);
        $this->assertSame(1, $record->context['row_count'] ?? null);
        $this->assertSame('req-query-1', $record->context['request_id_filter'] ?? null);
    }

    public function testOverLimitLogsError(): void
    {
        $capture = CapturedIdpLogging::create();
        $service = $this->service($capture);

        try {
            $service->searchTrace(['limit' => 900]);
            $this->fail('Expected over_limit');
        } catch (\InvalidArgumentException) {
        }

        $record = $this->findAppMessage($capture, 'logs query rejected');
        $this->assertSame('over_limit', $record->context['reason'] ?? null);
    }

    public function testInvalidCursorLogsError(): void
    {
        $capture = CapturedIdpLogging::create();
        $service = $this->service($capture);

        try {
            $service->searchTrace(['limit' => 10, 'cursor' => '!!!']);
            $this->fail('Expected invalid cursor');
        } catch (\InvalidArgumentException) {
        }

        $record = $this->findAppMessage($capture, 'logs query rejected');
        $this->assertSame('invalid_cursor', $record->context['reason'] ?? null);
    }

    private function service(CapturedIdpLogging $capture): LogsQueryService
    {
        $paths = new LogPathResolver($this->tmpdir);
        $query = new SqliteLogQuery($paths, new SqliteLogSchema());

        return new LogsQueryService($query, $capture->idpLogging);
    }

    private function seedRequest(string $requestId): void
    {
        $paths = new LogPathResolver($this->tmpdir);
        $handler = new JsonLogSpoolHandler($paths);
        $handler->handle(new LogRecord(
            new \DateTimeImmutable('2026-10-08T12:00:01+00:00'),
            'app',
            Level::Info,
            'seed line',
            [],
            ['request_id' => $requestId],
        ));
        (new LogSpoolDrainer($paths, new SqliteLogSchema(), new MonologJsonLineParser()))->drainOnce();
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

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
