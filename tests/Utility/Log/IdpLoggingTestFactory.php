<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Log;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\LogChannelFactory;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Monolog\Level;

/** Minimal IdpLogging for unit tests (no spool/stderr). */
final class IdpLoggingTestFactory
{
    public static function create(): IdpLogging
    {
        $root = sys_get_temp_dir() . '/idp-log-test-' . bin2hex(random_bytes(2));
        mkdir($root, 0775, true);
        $factory = new LogChannelFactory(
            new LogPathResolver($root),
            Level::Debug,
            false,
            false,
            $root . '/app.log',
        );

        return new IdpLogging($factory);
    }
}
