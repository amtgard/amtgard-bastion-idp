<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\ORN\OrnClassMap;
use Optional\Optional;

final class IamServiceValidator
{
    public static function validate(?string $iamService): ?string
    {
        return Optional::ofNullable($iamService)
            ->filter(fn (string $value) => trim($value) !== '')
            ->map(function (string $value): string {
                $value = trim($value);
                OrnClassMap::validateCustomPrefix($value);

                return $value;
            })
            ->orElse(null);
    }
}
