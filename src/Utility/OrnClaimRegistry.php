<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IdP\Models\Orn\ClientApplicationClaim;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Optional\Optional;

class OrnClaimRegistry
{
    public static function registerForClient(Client $client): void
    {
        Optional::ofNullable($client->getIamService())
            ->filter(fn (string $service) => $service !== '')
            ->ifPresent(function (string $service) use ($client): void {
                // Each integrator may define a distinct proviso layout; bind it before parsing claims.
                $format = IamServiceFormatParser::parse($client->getIamServiceFormat());
                ClientApplicationFormatRegistry::register($service, $format);
                self::registerForService($service);
            });
    }

    public static function registerForService(string $service): void
    {
        if ($service === ServiceCatalog::Idp->value) {
            return;
        }

        if (OrnClassMap::isRegistered($service)) {
            return;
        }

        // Built-in enum names are owned by orn-definitions; only custom strings become ClientApplicationClaim.
        if (ServiceCatalog::tryFrom($service) !== null) {
            return;
        }

        OrnClassMap::registerClaim($service, ClientApplicationClaim::class);
    }
}
