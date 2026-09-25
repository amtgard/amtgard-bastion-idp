<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

final class MailboxChallengeIssueResult
{
    public function __construct(
        public readonly string $challengeId,
        public readonly string $sentToHash,
        public readonly bool $mailed,
    ) {
    }
}
