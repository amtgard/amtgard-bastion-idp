<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Client\Entities;

use Amtgard\ActiveRecordOrm\Attribute\EntityOf;
use Amtgard\ActiveRecordOrm\Attribute\Field;
use Amtgard\ActiveRecordOrm\Attribute\PrimaryKey;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Data;
use Amtgard\Traits\Builder\ToBuilder;
use DateTimeInterface;

#[EntityOf(MailboxChallengeRepository::class)]
class MailboxChallengeEntity extends RepositoryEntity
{
    use Builder;
    use ToBuilder;
    use Data;

    #[PrimaryKey]
    #[Field('id')]
    private string $id;

    #[Field('purpose')]
    private string $purpose;

    #[Field('idp_user_id')]
    private ?string $idpUserId;

    #[Field('mundane_id')]
    private ?int $mundaneId;

    #[Field('code_hash')]
    private string $codeHash;

    #[Field('sent_to_hash')]
    private string $sentToHash;

    #[Field('new_email')]
    private ?string $newEmail;

    #[Field('attempts')]
    private int $attempts;

    #[Field('send_count')]
    private int $sendCount;

    #[Field('stage')]
    private ?string $stage;

    #[Field('expires_at')]
    private DateTimeInterface $expiresAt;

    #[Field('consumed_at')]
    private ?DateTimeInterface $consumedAt;

    #[Field('created_at')]
    private DateTimeInterface $createdAt;
}
