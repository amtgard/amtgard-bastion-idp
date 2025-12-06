<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Auth\Entities\AuthCodeEntity as OAuthAuthCodeEntity;
use Amtgard\IdP\AuthClient\Repositories\AuthCode;
use Amtgard\IdP\AuthClient\Repositories\Client;
use Amtgard\IdP\Persistence\Repositories\UserRepository;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Optional\Optional;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    private Client $clients;
    private UserRepository $users;
    private AuthCode $authCodes;

    public function __construct(EntityManager  $entityManager,
                                Client         $clients,
                                UserRepository $users,
                                AuthCode       $authCodes)
    {
        $this->clients = $clients;
        $this->users = $users;
        $this->authCodes = $authCodes;
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
        $client = Optional::ofNullable($this->clients->findByClientId($authCodeEntity->getClient()->getIdentifier()))
            ->orElseThrow(new \RuntimeException('Client not found'));

        // Find the user if there is a user identifier
        $user = Optional::ofNullable($this->users->findUserByUserId($authCodeEntity->getUserIdentifier()))
            ->orElseThrow(new \RuntimeException('UserRepository not found'));

        // Create a new auth code entity
        $authCode = $this->authCodes->createAuthCode($authCodeEntity, $client, $user);
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
        $authCode = $this->authCodes->fetchAuthCodeById($codeId);
        $authCode->revoked = true;
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
        $authCode = $this->authCodes->fetchAuthCodeById($codeId);
        return $authCode->revoked || $authCode->expires_at > new \DateTime();
    }
}