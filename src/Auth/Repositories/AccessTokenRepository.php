<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use DateTime;
use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Amtgard\IdP\Entity\AccessToken as AccessTokenEntity;
use Amtgard\IdP\Entity\Client as ClientEntity;
use Amtgard\IdP\Entity\User as UserEntity;
use Amtgard\IdP\Auth\Entities\AccessTokenEntity as OAuthAccessTokenEntity;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Create a new access token
     *
     * @param ClientEntityInterface  $clientEntity
     * @param array                  $scopes
     * @param string|null            $userIdentifier
     *
     * @return AccessTokenEntityInterface
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        $accessToken = new OAuthAccessTokenEntity();
        $accessToken->setClient($clientEntity);
        $accessToken->setUserIdentifier($userIdentifier);
        
        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }
        
        return $accessToken;
    }

    /**
     * Persists a new access token to permanent storage.
     *
     * @param AccessTokenEntityInterface $accessTokenEntity
     *
     * @return void
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        // Find the client
        $clientRepo = $this->entityManager->getRepository(ClientEntity::class);
        $client = $clientRepo->findOneBy(['clientId' => $accessTokenEntity->getClient()->getIdentifier()]);
        
        if ($client === null) {
            throw new \RuntimeException('Client not found');
        }
        
        // Find the user if there is a user identifier
        $user = null;
        if ($accessTokenEntity->getUserIdentifier() !== null) {
            $userRepo = $this->entityManager->getRepository(UserEntity::class);
            $user = $userRepo->find($accessTokenEntity->getUserIdentifier());
            
            if ($user === null) {
                throw new \RuntimeException('User not found');
            }
        }
        
        // Create a new access token entity
        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier($accessTokenEntity->getIdentifier());
        $accessToken->setClient($client);
        $accessToken->setUser($user);
        
        // Extract scopes
        $scopes = [];
        foreach ($accessTokenEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }
        $accessToken->setScopes($scopes);
        
        // Set expiry date
        $accessToken->setExpiresAt(
            (new DateTime())->setTimestamp($accessTokenEntity->getExpiryDateTime()->getTimestamp())
        );
        
        // Persist the access token
        $this->entityManager->persist($accessToken);
        $this->entityManager->flush();
    }

    /**
     * Revoke an access token.
     *
     * @param string $tokenId
     *
     * @return void
     */
    public function revokeAccessToken($tokenId)
    {
        $accessTokenRepo = $this->entityManager->getRepository(AccessTokenEntity::class);
        $accessToken = $accessTokenRepo->findOneBy(['identifier' => $tokenId]);
        
        if ($accessToken !== null) {
            $accessToken->setRevoked(true);
            $this->entityManager->flush();
        }
    }

    /**
     * Check if the access token has been revoked.
     *
     * @param string $tokenId
     *
     * @return bool Return true if this token has been revoked
     */
    public function isAccessTokenRevoked($tokenId)
    {
        $accessTokenRepo = $this->entityManager->getRepository(AccessTokenEntity::class);
        $accessToken = $accessTokenRepo->findOneBy(['identifier' => $tokenId]);
        
        if ($accessToken === null) {
            return true;
        }
        
        return $accessToken->isRevoked() || $accessToken->isExpired();
    }
}