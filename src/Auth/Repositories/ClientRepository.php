<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Amtgard\IdP\Entity\Client as ClientEntity;
use Amtgard\IdP\Auth\Entities\ClientEntity as OAuthClientEntity;

class ClientRepository implements ClientRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Get a client by the client identifier.
     *
     * @param string $clientIdentifier The client's identifier
     * @param string|null $grantType The grant type used (if sent)
     * @param string|null $clientSecret The client's secret (if sent)
     * @param bool $mustValidateSecret If true the client must attempt to validate the secret if the client
     *                                        is confidential
     *
     * @return ClientEntityInterface|null
     */
    public function getClientEntity($clientIdentifier, $grantType = null, $clientSecret = null, $mustValidateSecret = true)
    {
        $clientRepo = $this->entityManager->getRepository(ClientEntity::class);
        
        /** @var ClientEntity|null $client */
        $client = $clientRepo->findOneBy(['clientId' => $clientIdentifier]);
        
        // Check if client exists
        if ($client === null) {
            return null;
        }
        
        // Check if grant type is allowed
        if ($grantType !== null && !in_array($grantType, $client->getAllowedGrantTypes(), true)) {
            return null;
        }
        
        // Check if client secret is valid for confidential clients
        if ($mustValidateSecret && $client->isConfidential() && !$this->validateClientSecret($client, $clientSecret)) {
            return null;
        }
        
        // Create and return the client entity
        $clientEntity = new OAuthClientEntity();
        $clientEntity->setIdentifier($client->getClientId());
        $clientEntity->setName($client->getName());
        $clientEntity->setRedirectUri($client->getRedirectUri());
        $clientEntity->setConfidential($client->isConfidential());
        
        return $clientEntity;
    }
    
    /**
     * Validate a client's secret.
     *
     * @param ClientEntity $client
     * @param string|null $clientSecret
     *
     * @return bool
     */
    private function validateClientSecret(ClientEntity $client, ?string $clientSecret): bool
    {
        if ($clientSecret === null) {
            return false;
        }
        
        return hash_equals($client->getClientSecret(), $clientSecret);
    }
}