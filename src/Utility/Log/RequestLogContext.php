<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

/** Process-scoped request correlation and identity for log processors. */
final class RequestLogContext
{
    private static ?string $requestId = null;

    private static ?string $userUuid = null;

    private static ?string $clientId = null;

    private static ?string $aud = null;

    public static function set(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    public static function setIdentity(string $userUuid, string $aud): void
    {
        self::$userUuid = $userUuid;
        self::$aud = $aud;
        self::$clientId = $aud;
    }

    public static function userUuid(): ?string
    {
        return self::$userUuid;
    }

    public static function clientId(): ?string
    {
        return self::$clientId;
    }

    public static function aud(): ?string
    {
        return self::$aud;
    }

    public static function reset(): void
    {
        self::$requestId = null;
        self::$userUuid = null;
        self::$clientId = null;
        self::$aud = null;
    }
}
