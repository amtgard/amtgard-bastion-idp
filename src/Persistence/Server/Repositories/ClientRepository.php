<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\Interface\EntityRepositoryInterface;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Grant;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Optional\Optional;

#[RepositoryOf('clients', Client::class)]
class ClientRepository extends Repository implements EntityRepositoryInterface, ClientRepositoryInterface
{
    static function getTableName()
    {
        return 'clients';
    }

    public static function getEntityClass()
    {
        return Client::class;
    }

    public function findActiveClientsForUser($userId)
    {
        $this->query("SELECT c.id, c.name
                      FROM user_client_authorizations uca
                      LEFT JOIN clients c ON uca.client_id = c.id
                      INNER JOIN users u on uca.user_identifier = u.user_id
                      WHERE u.id = :user_id
                      GROUP by c.client_id");
        $this->user_id = $userId;
        $this->execute();

        $clients = [];
        while ($this->next()) {
            /** @var Client $client */
            $client = $this->getEntity();
            $clients[] = [
                'client_id' => $client->id,
                'client_name' => $client->name
            ];
        }
        return $clients;
    }

    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        /** @var Client $client */
        $client = $this->fetchBy('identifier', $clientIdentifier);
        return Optional::ofNullable($client)
            ->map(fn($client) => OAuthClient::builder()
                ->clientEntity($client)
                ->identifier($client->getIdentifier())
                ->isConfidential($client->getIsConfidential())
                ->name($client->getName())
                ->redirectUri($client->getRedirectUri())
                ->build())
            ->orElse(null);
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        /** @var OAuthClient $client */
        $client = $this->getClientEntity($clientIdentifier);
        $self = $this;

        return Optional::ofNullable($client)
            ->map(function ($client) use ($clientSecret, $grantType, $self) {
                $gate = $client->getClientEntity()->getClientSecret() == $clientSecret;
                $gate &= $self->validateGrant($client, $grantType);
                return $gate;
            })
            ->orElse(false);
    }

    private function validateGrant(OAuthClient $client, string $grantType): bool
    {
        return true;
    }
}