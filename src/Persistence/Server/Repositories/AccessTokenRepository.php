<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAccessToken;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Ramsey\Uuid\Uuid;

#[RepositoryOf('access_tokens', AccessToken::class)]
class AccessTokenRepository extends Repository implements AccessTokenRepositoryInterface
{

    static function getTableName()
    {
        return 'access_tokens';
    }

    public static function getEntityClass()
    {
        return AccessToken::class;
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        /** @var OAuthClient $client */
        $client = $clientEntity;
        $tokenId = Uuid::uuid4()->toString();
        $expiryDatetime = new \DateTimeImmutable('+90 days');
        $accessToken = AccessToken::builder()
            ->identifier($tokenId)
            ->client($client->getClientEntity())
            ->userIdentifier($userIdentifier)
            ->expiryDateTime($expiryDatetime)
            ->build();

        $oauthAccessToken = OAuthAccessToken::builder()
            ->client($client)
            ->identifier($tokenId)
            ->accessTokenEntity($accessToken)
            ->scopes($scopes)
            ->userIdentifier($userIdentifier)
            ->expiryDateTime($expiryDatetime)
            ->build();

        return $oauthAccessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        /** @var OAuthAccessToken $accessToken */
        $oAuthAccessToken = $accessTokenEntity;
        /** @var AccessToken $accessToken */
        $accessToken = $oAuthAccessToken->getAccessTokenEntity();
        $accessToken->setClient($oAuthAccessToken->getClient()->getClientEntity());
        $accessToken->setIdentifier($oAuthAccessToken->getIdentifier());
        $accessToken->setExpiryDateTime($oAuthAccessToken->getExpiryDateTime());
        $accessToken->setUserIdentifier($oAuthAccessToken->getUserIdentifier());
        $accessToken->persist($accessToken->getMapper());
    }

    public function revokeAccessToken($tokenId)
    {
        /** @var AccessToken $accessToken */
        $accessToken = $this->fetchBy('identifier', $tokenId);
        $accessToken->setExpiryDateTime(new \DateTimeImmutable('now'));
        $accessToken->persist($accessToken->getMapper());
    }

    public function isAccessTokenRevoked($tokenId)
    {
        /** @var AccessToken $accessToken */
        $accessToken = $this->fetchBy('identifier', $tokenId);
        return $accessToken->getExpiryDateTime() < new \DateTimeImmutable('now');
    }
}
