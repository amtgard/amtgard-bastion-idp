<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAuthCode;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AuthCode;
use Amtgard\IdP\Utility\Utility;
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
        $expiryDatetime = Utility::dateFrom(new \DateInterval($_ENV['OAUTH_AUTH_TOKEN_TTL']));
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
        /** @var OAuthClient $oAuthClient */
        $oAuthClient = $oAuthAuthCode->getClient();
        $client = EntityManager::getManager()->getRepository(ClientRepository::class)->fetchBy('identifier', $oAuthClient->getIdentifier());
        $authCode->setClient($client);
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

    public function deleteExpiredAuthCodes()
    {
        $this->query("DELETE FROM auth_codes WHERE expiry_date_time < NOW()");
        $this->execute();
    }
}