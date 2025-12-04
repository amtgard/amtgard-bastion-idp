<?php

namespace Amtgard\IdP\AuthClient\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Entity;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Trait\EntityTrait;
use Amtgard\ActiveRecordOrm\Trait\RepositoryEntityTrait;
use Amtgard\IdP\AuthClient\Repositories\UserRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;
use DateTime;

#[EntityOf(UserRepository::class)]
class UserEntity extends RepositoryEntity
{
    use Builder, ToBuilder, Data, RepositoryEntityTrait;

    #[PrimaryKey]
    private int $id;

    #[Field('email')]
    private ?string $email;

    #[Field('password')]
    private ?string $password;

    #[Field('first_name')]
    private ?string $firstName;

    #[Field('last_name')]
    private ?string $lastName;

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

    #[Field('username')]
    private ?string $username;

    #[Field('ork_user_id')]
    private ?int $orkUserId;

    #[Field('user_id')]
    private ?string $userId;
}