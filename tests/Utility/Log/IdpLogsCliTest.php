<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Utility\Log\IdpLogsCli;
use Amtgard\IdP\Utility\Log\Sqlite\JsonLogSpoolHandler;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Amtgard\IdP\Utility\Log\Sqlite\LogSpoolDrainer;
use Amtgard\IdP\Utility\Log\Sqlite\MonologJsonLineParser;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogSchema;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class IdpLogsCliTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/idp-cli-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0775, true);
        $_ENV['LOG_ROOT'] = $this->tmpdir;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpdir);
    }

    public function testQueryLogsInfoWithRowCount(): void
    {
        $this->seedRequest('req-cli-1');
        [$cli, $handler] = IdpLogsCli::withTestLogger($this->tmpdir);

        $lines = $cli->queryRequestId('req-cli-1');
        $this->assertCount(1, $lines);
        $this->assertTrue($handler->hasInfoRecords());
        $this->assertSame('query', $handler->getRecords()[0]->context['command'] ?? null);
        $this->assertSame(1, $handler->getRecords()[0]->context['row_count'] ?? null);
    }

    private function seedRequest(string $requestId): void
    {
        $paths = new LogPathResolver($this->tmpdir);
        $handler = new JsonLogSpoolHandler($paths);
        $handler->handle(new LogRecord(
            new \DateTimeImmutable('2026-10-08T12:00:01+00:00'),
            'app',
            Level::Info,
            'cli seed',
            [],
            ['request_id' => $requestId],
        ));
        (new LogSpoolDrainer($paths, new SqliteLogSchema(), new MonologJsonLineParser()))->drainOnce();
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
