<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(UserJwtGenerationRepository::class)]
class UserJwtGeneration extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey('id', 'int')]
    protected $id;

    #[Field('user_id')]
    private int $userId;

    #[Field('user_uuid')]
    private string $userUuid;

    #[Field('client_id')]
    private ?int $clientId = null;

    #[Field('aud')]
    private string $aud;

    #[Field('pvh')]
    private string $pvh;

    #[Field('prev_pvh')]
    private ?string $prevPvh = null;

    #[Field('policy_hash')]
    private string $policyHash;

    #[Field('updated_at')]
    private string $updatedAt;
}
