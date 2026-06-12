<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\OrkServices;

/**
 * Built-in ORK IAM service prefixes ({@see OrkServices}) that are shared across
 * all integrator authorization JWTs. Custom integrator iam_service names remain
 * scoped to the requesting OAuth client.
 */
final class BuiltInOrkPolicyServices
{
    /**
     * @return list<string>
     */
    public static function serviceNames(): array
    {
        return array_map(
            static fn (OrkServices $service): string => $service->value,
            OrkServices::cases()
        );
    }

    public static function isBuiltIn(string $service): bool
    {
        return OrkServices::tryFrom($service) !== null;
    }
}
