<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use DateTime;
use DateTimeInterface;

final class TestMailboxChallengeEntity extends MailboxChallengeEntity
{
    public function __construct(
        private string $testId = 'chal-1',
        private string $testPurpose = MailboxChallengePurpose::CLAIM_ORK,
        private ?string $testIdpUserId = '123',
        private ?string $testStage = null,
        private ?string $testNewEmail = 'new@example.com',
        private string $testSentToHash = 'hash',
        private ?DateTimeInterface $testConsumedAt = null,
        private ?DateTimeInterface $testExpiresAt = null,
    ) {
        $this->testExpiresAt = $testExpiresAt ?? new DateTime('+5 minutes');
    }

    public function getId(): string
    {
        return $this->testId;
    }

    public function getPurpose(): string
    {
        return $this->testPurpose;
    }

    public function getIdpUserId(): ?string
    {
        return $this->testIdpUserId;
    }

    public function getStage(): ?string
    {
        return $this->testStage;
    }

    public function getNewEmail(): ?string
    {
        return $this->testNewEmail;
    }

    public function getSentToHash(): string
    {
        return $this->testSentToHash;
    }

    public function getConsumedAt(): ?DateTimeInterface
    {
        return $this->testConsumedAt;
    }

    public function getExpiresAt(): DateTimeInterface
    {
        return $this->testExpiresAt;
    }
}
