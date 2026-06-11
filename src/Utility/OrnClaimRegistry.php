<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

use Amtgard\IAM\ORN\OrnClassMap;
use Amtgard\IAM\OrkServices;
use Amtgard\IdP\Models\Orn\ClientApplicationClaim;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;

class OrnClaimRegistry
{
    public static function registerForClient(Client $client): void
    {
        $service = $client->getIamService();
        if ($service === null || $service === '') {
            return;
        }

        $format = IamServiceFormatParser::parse($client->getIamServiceFormat());
        ClientApplicationFormatRegistry::register($service, $format);
        self::registerForService($service);
    }

    public static function registerForService(string $service): void
    {
        if ($service === OrkServices::Idp->value) {
            return;
        }

        if (OrnClassMap::isRegistered($service)) {
            return;
        }

        // Built-in ORK service prefixes are registered by orn-definitions or IDP bootstrap.
        if (OrkServices::tryFrom($service) !== null) {
            return;
        }

        OrnClassMap::registerClaim($service, ClientApplicationClaim::class);
    }
}
