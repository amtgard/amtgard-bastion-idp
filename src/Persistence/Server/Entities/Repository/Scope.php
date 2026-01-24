<?php

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthScope;
use Amtgard\IdP\Persistence\Server\Repositories\ScopeRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(ScopeRepository::class)]
class Scope extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey]
    protected int $id;

    #[Field('scope_id')]
    protected string $identifier;

}