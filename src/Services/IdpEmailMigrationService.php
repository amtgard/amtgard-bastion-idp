<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Optional\Optional;
use Psr\Log\LoggerInterface;

final class IdpEmailMigrationService
{
    public function __construct(
        private MailboxChallengeService $challenges,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function start(UserEntity $user, string $newEmail): MailboxChallengeIssueResult
    {
        $proposed = strtolower(trim($newEmail));
        if (!filter_var($proposed, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid new email');
        }

        $current = (string) $user->getEmail();
        $result = $this->challenges->issue(
            MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            $current,
            $user->getUserId(),
            null,
            $proposed,
            MailboxChallengePurpose::STAGE_PENDING_CURRENT,
        );
        $this->logger->info('mailbox.email_migration.started', [
            'challenge_id' => $result->challengeId,
            'sent_to_hash' => $result->sentToHash,
        ]);

        return $result;
    }

    public function confirm(UserEntity $user, string $code): MailboxChallengeCheckResult
    {
        return Optional::ofNullable(
            $this->challenges->findLatestOpenByUserAndPurpose(
                $user->getUserId(),
                MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            )
        )
            ->filter(fn (MailboxChallengeEntity $row) => $row->getStage() === MailboxChallengePurpose::STAGE_PENDING_CURRENT)
            ->map(function (MailboxChallengeEntity $row) use ($code) {
                $checked = $this->challenges->check($row->getId(), $code);
                if (!$checked->ok()) {
                    return $checked;
                }

                $destination = (string) $row->getNewEmail();
                $this->challenges->advanceMigrationToNewMailbox($row->getId(), $destination);
                $this->logger->info('mailbox.email_migration.confirmed', [
                    'challenge_id' => $row->getId(),
                    'sent_to_hash' => hash('sha256', $destination),
                ]);

                return new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK, $row);
            })
            ->orElseGet(fn () => new MailboxChallengeCheckResult(MailboxChallengeCheckResult::UNKNOWN));
    }

    public function commit(UserEntity $user, string $code): MailboxChallengeCheckResult
    {
        return Optional::ofNullable(
            $this->challenges->findLatestOpenByUserAndPurpose(
                $user->getUserId(),
                MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            )
        )
            ->filter(fn (MailboxChallengeEntity $row) => $row->getStage() === MailboxChallengePurpose::STAGE_PENDING_NEW)
            ->map(function (MailboxChallengeEntity $row) use ($user, $code) {
                $checked = $this->challenges->check($row->getId(), $code);
                if (!$checked->ok()) {
                    return $checked;
                }

                $this->challenges->consume($row->getId());
                $this->users->updateEmail($user, (string) $row->getNewEmail());
                $this->logger->info('mailbox.email_migration.committed', [
                    'challenge_id' => $row->getId(),
                    'sent_to_hash' => $row->getSentToHash(),
                ]);

                return new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK, $row);
            })
            ->orElseGet(fn () => new MailboxChallengeCheckResult(MailboxChallengeCheckResult::UNKNOWN));
    }
}
