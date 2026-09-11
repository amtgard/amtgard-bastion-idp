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
        foreach (self::serviceClaimExtensionTable() as $rule) {
            if ($rule['when']($service)) {
                if ($rule['action'] === 'register') {
                    OrnClassMap::registerClaim($service, ClientApplicationClaim::class);
                }

                return;
            }
        }
    }

    /**
     * First matching rule wins: built-in catalog and already-registered prefixes are no-ops;
     * otherwise register {@see ClientApplicationClaim} for custom integrator service names.
     *
     * @return list<array{when: callable(string): bool, action: 'noop'|'register'}>
     */
    private static function serviceClaimExtensionTable(): array
    {
        return [
            [
                'when' => static fn (string $service): bool => $service === ServiceCatalog::Idp->value,
                'action' => 'noop',
            ],
            [
                'when' => static fn (string $service): bool => OrnClassMap::isRegistered($service),
                'action' => 'noop',
            ],
            [
                'when' => static fn (string $service): bool => BuiltInOrkPolicyServices::isBuiltIn($service),
                'action' => 'noop',
            ],
            [
                'when' => static fn (string $service): bool => true,
                'action' => 'register',
            ],
        ];
    }
}
