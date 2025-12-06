<?php

namespace Amtgard\IdP\Persistence\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\Trait\RepositoryEntityTrait;
use Amtgard\IdP\Persistence\UserRepository;
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