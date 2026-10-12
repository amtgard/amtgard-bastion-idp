<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;

/** Verifies LOG_ROOT is writable once IdpLogging is available (container boot). */
final class LogRootBootGuard
{
    public static function verify(LogPathResolver $paths, IdpLogging $logging): void
    {
        $root = $paths->root();
        $paths->ensureDirectory($root . '/trace');
        $paths->ensureDirectory($paths->spoolDirectory());

        if (! is_writable($root)) {
            $logging->app()->emergency('log root is not writable at boot', [
                'log_root' => $root,
            ]);
        }
    }
}
