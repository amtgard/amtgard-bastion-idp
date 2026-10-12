<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Utility\Log\Sqlite\JsonLogSpoolHandler;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Amtgard\IdP\Utility\Log\Sqlite\LogSpoolDrainer;
use Amtgard\IdP\Utility\Log\Sqlite\MonologJsonLineParser;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogQuery;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogSchema;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SqliteLogBundleTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/idp-log-' . bin2hex(random_bytes(4));
        mkdir($this->tmpdir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpdir);
    }

    public function testSpoolDrainsIntoHourlySqliteAndQueryFindsRequestId(): void
    {
        $paths = new LogPathResolver($this->tmpdir);
        $handler = new JsonLogSpoolHandler($paths);
        $handler->handle(new LogRecord(
            new \DateTimeImmutable('2026-10-08T12:00:01+00:00'),
            'app',
            Level::Info,
            'oauth token issued',
            ['client_id' => 'integ_confidential'],
            ['request_id' => 'req-abc-123'],
        ));

        $drainer = new LogSpoolDrainer($paths, new SqliteLogSchema(), new MonologJsonLineParser());
        $this->assertSame(1, $drainer->drainOnce());
        $this->assertSame(0, $drainer->drainOnce());

        $found = (new SqliteLogQuery($paths, new SqliteLogSchema()))->rawLinesForRequestId('req-abc-123');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('req-abc-123', $found[0]);
        $this->assertStringContainsString('oauth token issued', $found[0]);
    }

    public function testParserIgnoresBlankAndInvalidLines(): void
    {
        $parser = new MonologJsonLineParser();
        $this->assertNull($parser->parse(''));
        $this->assertNull($parser->parse('not-json'));
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
