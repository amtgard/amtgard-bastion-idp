<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility;

class AppleLoginFeature
{
    public static function isEnabled(): bool
    {
        return filter_var($_ENV['APPLE_LOGIN_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
