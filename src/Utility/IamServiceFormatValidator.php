<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

final class IamServiceFormatValidator
{
    public static function validate(?string $iamServiceFormat): ?string
    {
        if ($iamServiceFormat === null || trim($iamServiceFormat) === '') {
            return null;
        }

        $format = IamServiceFormatParser::parse(trim($iamServiceFormat));
        return IamServiceFormatParser::encode($format);
    }
}
