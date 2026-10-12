<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Process-scoped IdpLogging for EntityManager repositories and static helpers. */
final class IdpLoggingRuntime
{
    private static ?IdpLogging $instance = null;

    public static function bind(IdpLogging $logging): void
    {
        self::$instance = $logging;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function app(): LoggerInterface
    {
        return self::$instance?->app() ?? new NullLogger();
    }

    public static function security(): LoggerInterface
    {
        return self::$instance?->security() ?? new NullLogger();
    }

    public static function oauth(): LoggerInterface
    {
        return self::$instance?->oauth() ?? new NullLogger();
    }

    public static function pvh(): LoggerInterface
    {
        return self::$instance?->pvh() ?? new NullLogger();
    }

    public static function audit(): LoggerInterface
    {
        return self::$instance?->audit() ?? new NullLogger();
    }
}
