<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORN\OrnSegmentLabel;

final class IamServiceFormatParser
{
    /**
     * @return list<ServiceCatalog|string>
     */
    public static function parse(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return self::defaultFormat();
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded) === false) {
            throw new \InvalidArgumentException('iam_service_format must be a JSON array of proviso slot names.');
        }

        $format = [];
        foreach ($decoded as $slot) {
            if (!is_string($slot) || trim($slot) === '') {
                throw new \InvalidArgumentException('iam_service_format entries must be non-empty strings.');
            }

            $label = OrnSegmentLabel::from(trim($slot));
            $format[] = $label->toCatalogEntry() ?? $label->name;
        }

        return $format;
    }

    /**
     * @return list<ServiceCatalog|string>
     */
    public static function defaultFormat(): array
    {
        return [
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
        ];
    }

    /**
     * @param list<ServiceCatalog|string> $format
     */
    public static function encode(array $format): string
    {
        return json_encode(
            array_map(
                static fn (ServiceCatalog|string $slot): string => $slot instanceof ServiceCatalog ? $slot->value : $slot,
                $format
            ),
            JSON_THROW_ON_ERROR
        );
    }
}
