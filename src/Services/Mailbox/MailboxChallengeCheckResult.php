<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;

final class MailboxChallengeCheckResult
{
    public const OK = 'ok';
    public const UNKNOWN = 'unknown';
    public const WRONG = 'wrong';
    public const EXPIRED = 'expired';
    public const CONSUMED = 'consumed';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $reason,
        public readonly ?MailboxChallengeEntity $challenge = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->reason === self::OK;
    }
}
