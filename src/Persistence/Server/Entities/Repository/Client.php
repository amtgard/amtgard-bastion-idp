<?php

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(ClientRepository::class)]
class Client extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey('id', 'int')]
    protected $id;

    #[Field('client_id')]
    protected string $identifier;

    #[Field('client_secret')]
    protected string $clientSecret;

    #[Field('name')]
    protected string $name;

    #[Field('redirect_uri')]
    protected string $redirectUri;

    #[Field('is_confidential', 'int')]
    protected bool $isConfidential = false;

}