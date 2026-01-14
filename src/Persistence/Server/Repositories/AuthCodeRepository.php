<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAuthCode;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AuthCode;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Ramsey\Uuid\Uuid;

#[RepositoryOf('auth_codes', AuthCode::class)]
class AuthCodeRepository extends Repository implements AuthCodeRepositoryInterface
{

    static function getTableName()
    {
        return 'auth_codes';
    }

    public static function getEntityClass()
    {
        return AuthCode::class;
    }

    public function getNewAuthCode()
    {
        $tokenId = Uuid::uuid4()->toString();
        $expiryDatetime = new \DateTimeImmutable('+1 month');
        /** @var AuthCode $authCode */
        $authCode = AuthCode::builder()
            ->expiryDateTime($expiryDatetime)
            ->identifier($tokenId)
            ->build();

        return OAuthAuthCode::builder()
            ->identifier($tokenId)
            ->expiryDateTime($expiryDatetime)
            ->authCodeEntity($authCode)
            ->build();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity)
    {
        /** @var OAuthAuthCode $oAuthAuthCode */
        $oAuthAuthCode = $authCodeEntity;
        /** @var AuthCode $authCode */
        $authCode = $oAuthAuthCode->getAuthCodeEntity();
        $authCode->setClient($oAuthAuthCode->getClient());
        $authCode->setIdentifier($oAuthAuthCode->getIdentifier());
        $authCode->setExpiryDateTime($oAuthAuthCode->getExpiryDateTime());
        $authCode->setUserIdentifier($oAuthAuthCode->getUserIdentifier());
        $authCode->setRedirectUri($oAuthAuthCode->getRedirectUri());
        $authCode->persist($authCode->getMapper());
    }

    public function revokeAuthCode($codeId)
    {
        /** @var AuthCode $authCode */
        $authCode = $this->fetchBy('identifier', $codeId);
        $authCode->setExpiryDateTime(new \DateTimeImmutable('now'));
        $authCode->persist($authCode->getMapper());
    }

    public function isAuthCodeRevoked($codeId)
    {
        /** @var AuthCode $authCode */
        $authCode = $this->fetchBy('identifier', $codeId);
        return $authCode->getExpiryDateTime() < new \DateTimeImmutable('now');
    }
}