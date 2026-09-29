<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use Amtgard\IdP\Utility\IamServiceFormatValidator;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use Optional\Optional;

/**
 * Service: reads and stores a confidential client's IAM proviso layout.
 */
final class ClientIamServiceFormatService
{
    /**
     * @return array{iam_service: ?string, service_format: list<string>, is_default: bool}
     */
    public function payload(Client $client): array
    {
        $slots = IamServiceFormatParser::parse($client->getIamServiceFormat());

        return [
            'iam_service' => $client->getIamService(),
            'service_format' => array_map(
                static fn (ServiceCatalog|string $slot): string => $slot instanceof ServiceCatalog ? $slot->value : $slot,
                $slots
            ),
            'is_default' => !$this->hasConfiguredFormat($client),
        ];
    }

    public function hasConfiguredFormat(Client $client): bool
    {
        return Optional::ofNullable($client->getIamServiceFormat())
            ->filter(fn (string $stored) => trim($stored) !== '')
            ->isPresent();
    }

    /**
     * @param array<string, mixed> $body
     */
    public function save(Client $client, array $body): void
    {
        $client->setIamServiceFormat($this->encodedFormat($body));
        EntityManager::getManager()->persist($client);
        OrnClaimRegistry::registerForClient($client);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodedFormat(array $body): string
    {
        if (!isset($body['service_format']) || !is_array($body['service_format'])) {
            throw new \InvalidArgumentException('service_format array is required');
        }

        $encoded = IamServiceFormatValidator::validate(
            json_encode($body['service_format'], JSON_THROW_ON_ERROR)
        );

        return Optional::ofNullable($encoded)
            ->orElseThrow(new \InvalidArgumentException('service_format must be a non-empty array'));
    }
}
