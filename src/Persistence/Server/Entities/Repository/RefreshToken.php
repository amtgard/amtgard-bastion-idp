<?php

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\EntityReference;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(RefreshTokenRepository::class)]
class RefreshToken extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey('id', 'int')]
    protected $id;

    #[Field('token_id')]
    protected string $identifier;

    protected string $accessTokenId;
    #[Field('access_token_id')]
    #[EntityReference('accessTokenId')]
    protected AccessToken $accessToken;

    #[Field('expiry_date_time')]
    protected \DateTime $expiryDateTime;

}