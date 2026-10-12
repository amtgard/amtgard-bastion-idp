<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Log\LogRootBootGuard;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use PHPUnit\Framework\TestCase;

final class LogRootBootGuardTest extends TestCase
{
    public function testUnwritableLogRootEmitsEmergency(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod read-only root is unreliable on Windows');
        }

        $tmpdir = sys_get_temp_dir() . '/idp-boot-' . bin2hex(random_bytes(4));
        mkdir($tmpdir, 0555, true);

        $capture = CapturedIdpLogging::create();
        LogRootBootGuard::verify(new LogPathResolver($tmpdir), $capture->idpLogging);

        $this->assertTrue($capture->handler('app')->hasEmergencyRecords());
        $record = $capture->handler('app')->getRecords()[0];
        $this->assertSame('log root is not writable at boot', $record->message);
        $this->assertSame($tmpdir, $record->context['log_root'] ?? null);

        chmod($tmpdir, 0755);
        rmdir($tmpdir);
    }
}
