<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Http;

use Amtgard\IdP\Utility\Log\RequestLogContext;

/**
 * Facade: reset per-request PHP state between FrankenPHP worker iterations.
 */
final class WorkerRequestScope
{
    public static function finish(int $outputBufferLevelAtStart = 0): void
    {
        while (ob_get_level() > $outputBufferLevelAtStart) {
            ob_end_clean();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (isset($_SESSION)) {
            unset($_SESSION);
        }

        RequestLogContext::reset();
    }
}
