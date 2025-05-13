<?php
declare(strict_types=1);

namespace Amtgard\IdP\Auth\Repositories;

use DateTime;
use Doctrine\ORM\EntityManager;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Amtgard\IdP\Entity\RefreshToken as RefreshTokenEntity;
use Amtgard\IdP\Entity\AccessToken as AccessTokenEntity;
use Amtgard\IdP\Auth\Entities\RefreshTokenEntity as OAuthRefreshTokenEntity;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Creates a new refresh token
     *
     * @param RefreshTokenEntityInterface $refreshTokenEntity
     *
     * @return void
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        // Find the access token
        $accessTokenRepo = $this->entityManager->getRepository(AccessTokenEntity::class);
        $accessToken = $accessTokenRepo->findOneBy(['identifier' => $refreshTokenEntity->getAccessToken()->getIdentifier()]);
        
        if ($accessToken === null) {
            throw new \RuntimeException('Access token not found');
        }
        
        // Create a new refresh token entity
        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier($refreshTokenEntity->getIdentifier());
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiresAt(
            (new DateTime())->setTimestamp($refreshTokenEntity->getExpiryDateTime()->getTimestamp())
        );
        
        // Persist the refresh token
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();
    }

    /**
     * Revoke the refresh token.
     *
     * @param string $tokenId
     *
     * @return void
     */
    public function revokeRefreshToken($tokenId)
    {
        $refreshTokenRepo = $this->entityManager->getRepository(RefreshTokenEntity::class);
        $refreshToken = $refreshTokenRepo->findOneBy(['identifier' => $tokenId]);
        
        if ($refreshToken !== null) {
            $refreshToken->setRevoked(true);
            $this->entityManager->flush();
        }
    }

    /**
     * Check if the refresh token has been revoked.
     *
     * @param string $tokenId
     *
     * @return bool Return true if this token has been revoked
     */
    public function isRefreshTokenRevoked($tokenId)
    {
        $refreshTokenRepo = $this->entityManager->getRepository(RefreshTokenEntity::class);
        $refreshToken = $refreshTokenRepo->findOneBy(['identifier' => $tokenId]);
        
        if ($refreshToken === null) {
            return true;
        }
        
        return $refreshToken->isRevoked() || $refreshToken->isExpired();
    }

    /**
     * Get a new refresh token.
     *
     * @return RefreshTokenEntityInterface
     */
    public function getNewRefreshToken()
    {
        return new OAuthRefreshTokenEntity();
    }
}