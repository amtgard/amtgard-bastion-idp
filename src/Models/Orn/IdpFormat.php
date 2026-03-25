<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\OrkService;
use Amtgard\IAM\ORNFormat;

class IdpFormat extends ORNFormat
{

    public static function serviceFormat(): array
    {
        return [
            OrkService::Configuration,
            OrkService::Game,
            OrkService::Kingdom,
            OrkService::Park,
        ];
    }

    public static function getValidResourceMap($resource = null): array
    {
        $map = [
            "IDP" => [ "EditClient", "EditIdentity" ]
        ];
        return $resource ? $map[$resource] : $map;
    }
}