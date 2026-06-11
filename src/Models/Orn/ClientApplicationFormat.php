<?php

namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\OrkServices;
use Amtgard\IAM\ORNFormat;

class ClientApplicationFormat extends ORNFormat
{
    public static function serviceFormat(): array
    {
        return [
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ];
    }

    public static function getValidResourceMap($resource = null): array
    {
        return ['*' => ['*']];
    }
}
