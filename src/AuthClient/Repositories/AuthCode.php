<?php
declare(strict_types=1);

namespace Amtgard\IdP\AuthClient\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\Entity;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\TableFactory;
use DateTime;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

class AuthCode
{
    private EntityMapper $authRepo;
    public function __construct(Database $database, UncachedDataAccessPolicy $tablePolicy) {
        $this->authRepo = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'oauth_auth_codes'))
            ->build();
    }

    public function createAuthCode(AuthCodeEntityInterface $authCodeEntity, Entity $client, Entity $user): Entity {
        $this->authRepo->clear();
        $this->authRepo->identifier = $authCodeEntity->getIdentifier();
        $this->authRepo->client_id = $client->id;
        $this->authRepo->user_id = $user->id;
        $this->authRepo->redirect_uri = $authCodeEntity->getRedirectUri();

        // Extract scopes
        $scopes = [];
        foreach ($authCodeEntity->getScopes() as $scope) {
            $scopes[] = $scope->getIdentifier();
        }
        $this->authRepo->scopes = json_encode($scopes);
        $this->authRepo->expires_at = (new DateTime())->setTimestamp($authCodeEntity->getExpiryDateTime()->getTimestamp());
        return $this->authRepo->createEntity();
    }

    public function fetchAuthCodeById($codeId) {
        return $this->authRepo->fetchBy('identifier', $codeId);
    }
}