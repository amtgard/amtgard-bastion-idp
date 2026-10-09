<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Log\LogRetentionPurger;
use Amtgard\IdP\Utility\Log\LogStream;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use PHPUnit\Framework\TestCase;

final class LogRetentionPurgerTest extends TestCase
{
    public function testPurgeLogsNoticeWithStreamAndCutoff(): void
    {
        $tmpdir = sys_get_temp_dir() . '/idp-purge-' . bin2hex(random_bytes(4));
        mkdir($tmpdir . '/trace/2020-01-01', 0775, true);
        touch($tmpdir . '/trace/2020-01-01/00.logs.sqlite');

        $capture = CapturedIdpLogging::create();
        $purger = new LogRetentionPurger(new LogPathResolver($tmpdir), $capture->idpLogging, 7, 30);
        $result = $purger->purgeStream(LogStream::Trace, 7);

        $this->assertSame('trace', $result['stream']);
        $this->assertSame(1, $result['files_deleted']);
        $record = $this->findAppMessage($capture, 'logs retention purge completed');
        $this->assertSame('trace', $record->context['stream'] ?? null);
        $this->assertSame(1, $record->context['files_deleted'] ?? null);
        $this->assertNotEmpty($record->context['cutoff'] ?? null);

        $this->removeTree($tmpdir);
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
