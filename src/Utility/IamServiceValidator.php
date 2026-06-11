<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\ORN\OrnClassMap;

final class IamServiceValidator
{
    public static function validate(?string $iamService): ?string
    {
        if ($iamService === null || $iamService === '') {
            return null;
        }

        $iamService = trim($iamService);
        if ($iamService === '') {
            return null;
        }

        OrnClassMap::validateCustomServiceName($iamService);

        return $iamService;
    }
}
