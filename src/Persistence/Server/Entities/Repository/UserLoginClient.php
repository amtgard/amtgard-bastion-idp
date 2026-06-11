<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;

#[EntityOf(UserLoginClientRepository::class)]
class UserLoginClient extends RepositoryEntity
{
    use Builder, Data;

    #[PrimaryKey('id', 'int')]
    protected $id;

    #[Field('user_id')]
    private int $userId;

    #[Field('login_id')]
    private int $loginId;

    #[Field('client_id')]
    private int $clientDbId;

    #[Field('metadata')]
    private string $metadata;

    #[Field('encoding')]
    private string $encoding;

    #[Field('updated_at')]
    private \DateTimeInterface $updatedAt;

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getLoginId(): int
    {
        return $this->loginId;
    }

    public function getClientDbId(): int
    {
        return $this->clientDbId;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
