<?php

declare(strict_types=1);


namespace Amtgard\IdP\Models\Orn;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORNFormat;
use Amtgard\IdP\Utility\Log\IdpLogging;

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
            'IDP' => [
                'EditClient',
                'EditIdentity',
                'ReadTraceLogs',
                'ReadErrorLogs',
                'AnalyzeLogs',
                'ExportAuditLogs',
            ],
        ];

        return $resource ? $map[$resource] : $map;
    }

    /** @param list<string> $actions */
    public static function announceLogReaderResources(IdpLogging $logging): void
    {
        static $announced = false;
        if ($announced) {
            return;
        }
        $announced = true;

        foreach (['ReadTraceLogs', 'ReadErrorLogs', 'AnalyzeLogs'] as $action) {
            $logging->app()->notice('idp log reader resource registered', [
                'resource' => 'IDP/' . $action,
            ]);
        }
    }
}
