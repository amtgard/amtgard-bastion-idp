<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;
use Optional\Optional;

final class IamServiceFormatValidator
{
    public static function validate(?string $iamServiceFormat): ?string
    {
        if ($iamServiceFormat === null || trim($iamServiceFormat) === '') {
            IdpLoggingRuntime::app()->debug('iam service format empty', [
                'reason' => 'empty_format',
            ]);
        }

        return Optional::ofNullable($iamServiceFormat)
            ->filter(fn (string $value) => trim($value) !== '')
            ->map(fn (string $value) => IamServiceFormatParser::encode(
                IamServiceFormatParser::parse(trim($value))
            ))
            ->orElse(null);
    }
}
