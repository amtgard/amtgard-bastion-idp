<?php

namespace Amtgard\IdP\Persistence\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Trait\RepositoryEntityTrait;
use Amtgard\IdP\Persistence\UserLoginRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;
use DateTime;

#[EntityOf(UserLoginRepository::class)]
class UserLoginEntity extends RepositoryEntity
{
    use Builder, ToBuilder, Data, RepositoryEntityTrait;
    #[PrimaryKey]
    private ?int $id;

    private ?int $userId;
    #[Field('user_id', 'userId')]
    private ?UserEntity $user;

    #[Field('password')]
    private ?string $password;

    #[Field('google_id')]
    private ?string $googleId;

    #[Field('facebook_id')]
    private ?string $facebookId;

    #[Field('avatar_url')]
    private ?string $avatarUrl;

    #[Field('created_at')]
    private ?DateTime $createdAt;

    #[Field('updated_at')]
    private ?DateTime $updatedAt;

}