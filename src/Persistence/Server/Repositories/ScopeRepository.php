<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\ActiveRecordOrm\Attribute\RepositoryOf;
use Amtgard\ActiveRecordOrm\Entity\Repository\Repository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthScope;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Optional\Optional;

#[RepositoryOf('scopes', Scope::class)]
class ScopeRepository extends Repository implements ScopeRepositoryInterface
{
    static function getTableName()
    {
        return 'scopes';
    }

    public static function getEntityClass()
    {
        return Scope::class;
    }

    public function getScopeEntityByIdentifier($identifier)
    {
        /** @var Scope $scope */
        $scope = $this->fetchBy('identifier', $identifier);
        return Optional::ofNullable($scope)
            ->map(fn($scope) => OAuthScope::builder()
                ->scopeEntity($scope)
                ->identifier($scope->getIdentifier())
                ->build())
            ->orElse(null);
    }

    public function finalizeScopes(array $scopes, $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null)
    {
        return $scopes;
    }
}