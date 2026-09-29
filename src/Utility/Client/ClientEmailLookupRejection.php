<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Client;

/**
 * Outcome object for a rejected confidential-client email lookup.
 */
enum ClientEmailLookupRejection
{
    case Missing;
    case Invalid;
    case Unknown;

    public function httpStatus(): int
    {
        return match ($this) {
            self::Unknown => 404,
            self::Missing, self::Invalid => 400,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Unknown => 'unknown email',
            self::Missing, self::Invalid => 'email is required',
        };
    }

    public function logReason(): string
    {
        return match ($this) {
            self::Missing => 'missing_email',
            self::Invalid => 'invalid_email',
            self::Unknown => 'unknown_email',
        };
    }
}
