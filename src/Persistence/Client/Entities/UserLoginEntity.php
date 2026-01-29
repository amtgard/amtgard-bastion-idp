<?php

namespace Amtgard\IdP\Persistence\Client\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\EntityReference;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;
use DateTime;
use DateTimeInterface;

#[EntityOf(UserLoginRepository::class)]
class UserLoginEntity extends RepositoryEntity
{
    use Builder, ToBuilder, Data;
    #[PrimaryKey]
    private ?int $id;

    #[Field('user_id')]
    private ?int $userId;

    #[EntityReference('userId')]
    private ?UserEntity $user;

    #[Field('password')]
    private ?string $password;

    #[Field('provider_id')]
    private ?string $providerId;

    #[Field('type')]
    private ?string $type;

    #[Field('avatar_url')]
    private ?string $avatarUrl;

    #[Field('created_at')]
    private ?DateTime $createdAt;

    #[Field('updated_at')]
    private ?DateTime $updatedAt;

    #[Field('refresh_token')]
    private ?string $refreshToken;

    #[Field('expiry_date_time')]
    private ?DateTimeInterface $expiryDateTime;

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}