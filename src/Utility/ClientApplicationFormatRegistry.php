<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\OrkServices;

final class ClientApplicationFormatRegistry
{
    /** @var array<string, list<OrkServices>> */
    private static array $formats = [];

    /**
     * @param list<OrkServices> $provisoSlots
     */
    public static function register(string $serviceIdentifier, array $provisoSlots): void
    {
        self::$formats[$serviceIdentifier] = $provisoSlots;
    }

    /**
     * @return list<OrkServices>
     */
    public static function get(string $serviceIdentifier): array
    {
        return self::$formats[$serviceIdentifier] ?? IamServiceFormatParser::defaultFormat();
    }

    public static function has(string $serviceIdentifier): bool
    {
        return isset(self::$formats[$serviceIdentifier]);
    }

    /** @internal */
    public static function reset(): void
    {
        self::$formats = [];
    }
}
