<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Optional\Optional;

final class IamServiceFormatValidator
{
    public static function validate(?string $iamServiceFormat): ?string
    {
        return Optional::ofNullable($iamServiceFormat)
            ->filter(fn (string $value) => trim($value) !== '')
            ->map(fn (string $value) => IamServiceFormatParser::encode(
                IamServiceFormatParser::parse(trim($value))
            ))
            ->orElse(null);
    }
}
