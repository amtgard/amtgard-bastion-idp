<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;

/**
 * Built-in ORK IAM service prefixes ({@see ServiceCatalog}) that are shared across
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
            static fn (ServiceCatalog $service): string => $service->value,
            ServiceCatalog::cases()
        );
    }

    public static function isBuiltIn(string $service): bool
    {
        return ServiceCatalog::tryFrom($service) !== null;
    }
}
