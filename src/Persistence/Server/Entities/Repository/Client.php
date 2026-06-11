<?php

namespace Amtgard\IdP\Persistence\Server\Entities\Repository;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
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

    #[Field('is_confidential')]
    private bool $isConfidential;

    #[Field('is_dev')]
    private bool $isDev;

    #[Field('iam_service')]
    private ?string $iamService = null;

    #[Field('iam_service_format')]
    private ?string $iamServiceFormat = null;

    public function getIamService(): ?string
    {
        return $this->iamService;
    }

    public function setIamService(?string $iamService): void
    {
        $this->iamService = $iamService;
    }

    public function getIamServiceFormat(): ?string
    {
        return $this->iamServiceFormat;
    }

    public function setIamServiceFormat(?string $iamServiceFormat): void
    {
        $this->iamServiceFormat = $iamServiceFormat;
    }
}