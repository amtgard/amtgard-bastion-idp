<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use DateTime;
use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Amtgard\IdP\Entity\AuthCode as AuthCodeEntity;
use Amtgard\IdP\Entity\Client as ClientEntity;
use Amtgard\IdP\Entity\User as UserEntity;
use Amtgard\IdP\Auth\Entities\AuthCodeEntity as OAuthAuthCodeEntity;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Creates a new AuthCode
     *
     * @return AuthCodeEntityInterface
     */
    public function getNewAuthCode()
    {
        return new OAuthAuthCodeEntity();
    }

    /**
     * Persists a new auth code to permanent storage.
     *
     * @param AuthCodeEntityInterface $authCodeEntity
     *
     * @return void
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        // Find the client
        $clientRepo = $this->entityManager->getRepository(ClientEntity::class);
        $client = $clientRepo->findOneBy(['clientId' => $authCodeEntity->getClient()->getIdentifier()]);
        
        if ($client === null) {
            throw new \RuntimeException('Client not found');
        }
        
        // Find the user if there is a user identifier
        $user = null;
        if ($authCodeEntity->getUserIdentifier() !== null) {
            $userRepo = $this->entityManager->getRepository(UserEntity::class);
            $user = $userRepo->find($authCodeEntity->getUserIdentifier());
            
            if ($user === null) {
                throw new \RuntimeException('User not found');
            }
        }
        
        // Create a new auth code entity
        $authCode = new AuthCodeEntity();
        $authCode->setIdentifier($authCodeEntity->getIdentifier());
        $authCode->setClient($client);
        $authCode->setUser($user);
        $authCode->setRedirectUri($authCodeEntity->getRedirectUri());
        
        // Extract scopes
        $scopes = [];
        foreach ($authCodeEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }
        $authCode->setScopes($scopes);
        
        // Set expiry date
        $authCode->setExpiresAt(
            (new DateTime())->setTimestamp($authCodeEntity->getExpiryDateTime()->getTimestamp())
        );
        
        // Persist the auth code
        $this->entityManager->persist($authCode);
        $this->entityManager->flush();
    }

    /**
     * Revoke an auth code.
     *
     * @param string $codeId
     *
     * @return void
     */
    public function revokeAuthCode($codeId)
    {
        $authCodeRepo = $this->entityManager->getRepository(AuthCodeEntity::class);
        $authCode = $authCodeRepo->findOneBy(['identifier' => $codeId]);
        
        if ($authCode !== null) {
            $authCode->setRevoked(true);
            $this->entityManager->flush();
        }
    }

    /**
     * Check if the auth code has been revoked.
     *
     * @param string $codeId
     *
     * @return bool Return true if this code has been revoked
     */
    public function isAuthCodeRevoked($codeId)
    {
        $authCodeRepo = $this->entityManager->getRepository(AuthCodeEntity::class);
        $authCode = $authCodeRepo->findOneBy(['identifier' => $codeId]);
        
        if ($authCode === null) {
            return true;
        }
        
        return $authCode->isRevoked() || $authCode->isExpired();
    }
}