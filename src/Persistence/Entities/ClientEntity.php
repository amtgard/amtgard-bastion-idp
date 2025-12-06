<?php

namespace Amtgard\IdP\Persistence\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Repositories\ClientRepository;
use DateTime;

#[EntityOf(ClientRepository::class)]
class ClientEntity extends RepositoryEntity
{
    #[PrimaryKey('id')]
    private ?int $id;
    #[Field('name')]
    private ?string $name;
    #[Field('client_id')]
    private ?string $clientId;
    #[Field('client_secret')]
    private ?string $clientSecret;
    #[Field('is_confidential')]
    private ?bool $isConfidential;
    #[Field('allowed_scopes')]
    private ?string $allowedScopes;
    #[Field('allowed_grant_types')]
    private ?string $allowedGrantTypes;
    #[Field('created_at')]
    private ?DateTime $createdAt;
    #[Field('updated_at')]
    private ?DateTime $updatedAt;
}