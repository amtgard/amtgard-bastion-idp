<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORNFormat;

class IdpFormat extends ORNFormat
{

    public static function ornSegmentSchema(): array
    {
        return [
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
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
