<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use Amtgard\IdP\Auth\Entities\ScopeEntity as OAuthScopeEntity;
use Amtgard\IdP\AuthClient\Repositories\Client as ClientEntity;
use Amtgard\IdP\AuthClient\Repositories\Scope as ScopeEntity;
use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Get a scope by the scope identifier.
     *
     * @param string $identifier The scope identifier
     *
     * @return ScopeEntityInterface|null
     */
    public function getScopeEntityByIdentifier($identifier)
    {
        $scopeRepo = $this->entityManager->getRepository(ScopeEntity::class);
        
        /** @var ScopeEntity|null $scope */
        $scope = $scopeRepo->findOneBy(['identifier' => $identifier]);
        
        if ($scope === null) {
            return null;
        }
        
        $scopeEntity = new OAuthScopeEntity();
        $scopeEntity->setIdentifier($scope->getIdentifier());
        
        return $scopeEntity;
    }

    /**
     * Given a client, grant type and optional user identifier validate the set of scopes requested are valid and optionally
     * append additional scopes or remove requested scopes.
     *
     * @param ScopeEntityInterface[] $scopes
     * @param string                 $grantType
     * @param ClientEntityInterface  $clientEntity
     * @param string|null            $userIdentifier
     *
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null)
    {
        // Get the client from the database
        $clientRepo = $this->entityManager->getRepository(ClientEntity::class);
        
        /** @var ClientEntity|null $client */
        $client = $clientRepo->findOneBy(['clientId' => $clientEntity->getIdentifier()]);
        
        if ($client === null) {
            return [];
        }
        
        // Get allowed scopes for the client
        $allowedScopes = $client->getAllowedScopes();
        
        // Filter requested scopes to only include those allowed for the client
        $filteredScopes = array_filter($scopes, function (ScopeEntityInterface $scope) use ($allowedScopes) {
            return in_array($scope->getIdentifier(), $allowedScopes, true);
        });
        
        // If no scopes are requested or all requested scopes were filtered out, return default scopes
        if (empty($filteredScopes)) {
            $scopeRepo = $this->entityManager->getRepository(ScopeEntity::class);
            $defaultScopes = $scopeRepo->findBy(['isDefault' => true]);
            
            $filteredScopes = [];
            foreach ($defaultScopes as $scope) {
                if (in_array($scope->getIdentifier(), $allowedScopes, true)) {
                    $scopeEntity = new OAuthScopeEntity();
                    $scopeEntity->setIdentifier($scope->getIdentifier());
                    $filteredScopes[] = $scopeEntity;
                }
            }
        }
        
        return $filteredScopes;
    }
}