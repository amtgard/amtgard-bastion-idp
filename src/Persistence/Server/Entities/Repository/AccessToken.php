<?php

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\EntityReference;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(AccessTokenRepository::class)]
class AccessToken extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey]
    protected int $id;

    #[Field('token_id')]
    protected string $identifier;

    #[Field('expiry_date_time')]
    protected \DateTime $expiryDateTime;

    #[Field('user_identifier')]
    protected string $userIdentifier;

    private int $clientId;

    #[Field('client_id')]
    #[EntityReference('clientId')]
    protected Client $client;
}