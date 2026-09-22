<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(ClientAccessRepository::class)]
class ClientAccess extends RepositoryEntity
{
    use Builder;
    use Data;

    #[PrimaryKey]
    protected int $id;

    #[Field('client_id')]
    protected int $clientDbId;

    #[Field('user_id')]
    protected int $userId;

    #[Field('created_at')]
    protected ?\DateTimeInterface $createdAt = null;
}
