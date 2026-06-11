<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\OrkServices;

final class IamServiceFormatParser
{
    /** Proviso slot names allowed in iam_service_format (not ORN service prefixes). */
    private const ALLOWED_PROVISO_SLOTS = [
        OrkServices::Configuration,
        OrkServices::Game,
        OrkServices::Kingdom,
        OrkServices::Park,
        OrkServices::Event,
        OrkServices::EventInstance,
        OrkServices::Mundane,
        OrkServices::Unit,
        OrkServices::ORK,
        OrkServices::Attendance,
        OrkServices::Awards,
        OrkServices::Audit,
        OrkServices::Cache,
        OrkServices::Tenant,
        OrkServices::Officer,
        OrkServices::Recommendations,
        OrkServices::Tournament,
    ];

    /**
     * @return list<OrkServices>
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
            $service = OrkServices::tryFrom($slot);
            if ($service === null || !in_array($service, self::ALLOWED_PROVISO_SLOTS, true)) {
                throw new \InvalidArgumentException("iam_service_format slot '$slot' is not an allowed proviso name.");
            }
            $format[] = $service;
        }

        return $format;
    }

    /**
     * @return list<OrkServices>
     */
    public static function defaultFormat(): array
    {
        return [
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ];
    }

    /**
     * @param list<OrkServices> $format
     */
    public static function encode(array $format): string
    {
        return json_encode(array_map(fn (OrkServices $s) => $s->value, $format), JSON_THROW_ON_ERROR);
    }
}
