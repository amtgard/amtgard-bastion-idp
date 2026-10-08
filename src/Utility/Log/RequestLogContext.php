<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

/** Process-scoped request correlation id for log processors. */
final class RequestLogContext
{
    private static ?string $requestId = null;

    public static function set(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    public static function reset(): void
    {
        self::$requestId = null;
    }
}
