<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAccessToken;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthRefreshToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\RefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Ramsey\Uuid\Uuid;

#[RepositoryOf('refresh_tokens', RefreshToken::class)]
class RefreshTokenRepository extends Repository implements RefreshTokenRepositoryInterface
{

    static function getTableName()
    {
        return 'refresh_tokens';
    }

    public static function getEntityClass()
    {
        return RefreshToken::class;
    }

    public function getNewRefreshToken()
    {
        $tokenId = Uuid::uuid4()->toString();
        $expiryDatetime = new \DateTimeImmutable('+1 month');
        /** @var RefreshToken $refreshToken */
        $refreshToken = RefreshToken::builder()
            ->identifier($tokenId)
            ->expiryDateTime($expiryDatetime)
            ->build();

        $oauthRefreshToken = OAuthRefreshToken::builder()
            ->identifier($tokenId)
            ->refreshTokenEntity($refreshToken)
            ->expiryDateTime($expiryDatetime)
            ->build();

        return $oauthRefreshToken;
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity)
    {
        /** @var OAuthRefreshToken $oAuthRefreshToken */
        $oAuthRefreshToken = $refreshTokenEntity;
        /** @var RefreshToken $refreshToken */
        $refreshToken = $oAuthRefreshToken->getRefreshTokenEntity();
        $refreshToken->setIdentifier($oAuthRefreshToken->getIdentifier());
        $refreshToken->setExpiryDateTime($oAuthRefreshToken->getExpiryDateTime());
        $accessToken = $oAuthRefreshToken->getAccessToken();
        if ($accessToken instanceof OAuthAccessToken) {
            $refreshToken->setAccessToken($accessToken->getAccessTokenEntity());
        } else {
            $refreshToken->setAccessToken($accessToken);
        }
        $refreshToken->persist($refreshToken->getMapper());
    }

    public function revokeRefreshToken($tokenId)
    {
        /** @var RefreshToken $refreshToken */
        $refreshToken = $this->fetchBy('identifier', $tokenId);
        $refreshToken->setExpiryDateTime(new \DateTimeImmutable('now'));
        $refreshToken->persist($refreshToken->getMapper());
    }

    public function isRefreshTokenRevoked($tokenId)
    {
        /** @var RefreshToken $refreshToken */
        $refreshToken = $this->fetchBy('identifier', $tokenId);
        return $refreshToken->getExpiryDateTime() < new \DateTimeImmutable('now');
    }
}