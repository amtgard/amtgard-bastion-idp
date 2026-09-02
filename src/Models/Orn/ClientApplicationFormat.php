<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORNFormat;

class ClientApplicationFormat extends ORNFormat
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
        return ['*' => ['*']];
    }
}
