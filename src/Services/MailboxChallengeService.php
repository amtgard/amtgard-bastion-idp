<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\Mailbox\OutboundMail;
use DateTime;
use Optional\Optional;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class MailboxChallengeService
{
    public const TTL_SECONDS = 600;
    public const RESERVE_TTL_SECONDS = 900;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS_PER_HOUR = 5;

    public function __construct(
        private MailboxChallengeRepository $challenges,
        private OutboundMail $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function issue(
        string $purpose,
        string $destinationEmail,
        ?string $idpUserId = null,
        ?int $mundaneId = null,
        ?string $newEmail = null,
        ?string $stage = null,
        ?string $challengeId = null,
    ): MailboxChallengeIssueResult {
        $this->assertKnownPurpose($purpose);
        $pepper = $this->pepper();
        $destination = strtolower(trim($destinationEmail));
        $sentToHash = hash('sha256', $destination);
        $since = (new DateTime())->modify('-1 hour');
        $alreadySent = $this->challenges->countSendsSince($sentToHash, $purpose, $since);

        if ($alreadySent >= self::MAX_SENDS_PER_HOUR) {
            $existingId = Optional::ofNullable($challengeId)
                ->filter(fn (string $id) => $id !== '')
                ->orElseGet(fn () => Uuid::uuid4()->toString());
            $this->logger->info('mailbox.challenge.throttled', [
                'challenge_id' => $existingId,
                'sent_to_hash' => $sentToHash,
                'purpose' => $purpose,
            ]);

            return new MailboxChallengeIssueResult($existingId, $sentToHash, false);
        }

        $id = Optional::ofNullable($challengeId)
            ->filter(fn (string $id) => $id !== '')
            ->orElseGet(fn () => Uuid::uuid4()->toString());
        $code = $this->generateCode();
        $now = new DateTime();
        $row = MailboxChallengeEntity::builder()
            ->id($id)
            ->purpose($purpose)
            ->idpUserId($idpUserId)
            ->mundaneId($mundaneId)
            ->codeHash($this->hashCode($code, $pepper))
            ->sentToHash($sentToHash)
            ->newEmail($newEmail)
            ->attempts(0)
            ->sendCount(1)
            ->stage($stage)
            ->expiresAt((clone $now)->modify('+' . self::TTL_SECONDS . ' seconds'))
            ->consumedAt(null)
            ->createdAt($now)
            ->build();
        $this->challenges->persist($row);
        $this->deliver($destination, $code, $id, $sentToHash, $purpose);

        return new MailboxChallengeIssueResult($id, $sentToHash, true);
    }

    public function reserve(
        string $purpose,
        string $idpUserId,
        ?int $mundaneId = null,
        int $ttlSeconds = self::RESERVE_TTL_SECONDS,
    ): MailboxChallengeEntity {
        $this->assertKnownPurpose($purpose);
        $now = new DateTime();
        $row = MailboxChallengeEntity::builder()
            ->id(Uuid::uuid4()->toString())
            ->purpose($purpose)
            ->idpUserId($idpUserId)
            ->mundaneId($mundaneId)
            ->codeHash('')
            ->sentToHash(hash('sha256', 'reserved:' . $idpUserId))
            ->newEmail(null)
            ->attempts(0)
            ->sendCount(0)
            ->stage(null)
            ->expiresAt((clone $now)->modify('+' . $ttlSeconds . ' seconds'))
            ->consumedAt(null)
            ->createdAt($now)
            ->build();
        $this->challenges->persist($row);
        $this->logger->info('mailbox.challenge.reserved', [
            'challenge_id' => $row->getId(),
            'sent_to_hash' => $row->getSentToHash(),
            'purpose' => $purpose,
        ]);

        return $row;
    }

    public function resend(string $challengeId, string $destinationEmail): MailboxChallengeIssueResult
    {
        $destination = strtolower(trim($destinationEmail));
        $sentToHash = hash('sha256', $destination);

        return Optional::ofNullable($this->challenges->findById($challengeId))
            ->filter(fn (MailboxChallengeEntity $row) => !Optional::ofNullable($row->getConsumedAt())->isPresent())
            ->map(function (MailboxChallengeEntity $row) use ($destination, $sentToHash, $challengeId) {
                $since = (new DateTime())->modify('-1 hour');
                $alreadySent = $this->challenges->countSendsSince($sentToHash, $row->getPurpose(), $since);
                if ($alreadySent >= self::MAX_SENDS_PER_HOUR) {
                    $this->logger->info('mailbox.challenge.throttled', [
                        'challenge_id' => $challengeId,
                        'sent_to_hash' => $sentToHash,
                        'purpose' => $row->getPurpose(),
                    ]);

                    return new MailboxChallengeIssueResult($challengeId, $sentToHash, false);
                }

                $code = $this->generateCode();
                $now = new DateTime();
                $updated = $row->toBuilder()
                    ->codeHash($this->hashCode($code, $this->pepper()))
                    ->sentToHash($sentToHash)
                    ->attempts(0)
                    ->sendCount($row->getSendCount() + 1)
                    ->expiresAt((clone $now)->modify('+' . self::TTL_SECONDS . ' seconds'))
                    ->build();
                $this->challenges->persist($updated);
                $this->deliver($destination, $code, $challengeId, $sentToHash, $row->getPurpose());

                return new MailboxChallengeIssueResult($challengeId, $sentToHash, true);
            })
            ->orElseGet(fn () => new MailboxChallengeIssueResult($challengeId, $sentToHash, false));
    }

    public function check(string $challengeId, string $code): MailboxChallengeCheckResult
    {
        return Optional::ofNullable($this->challenges->findById($challengeId))
            ->map(fn (MailboxChallengeEntity $row) => $this->evaluate($row, $code))
            ->orElseGet(fn () => new MailboxChallengeCheckResult(MailboxChallengeCheckResult::UNKNOWN));
    }

    public function consume(string $challengeId): bool
    {
        return Optional::ofNullable($this->challenges->findById($challengeId))
            ->filter(fn (MailboxChallengeEntity $row) => !Optional::ofNullable($row->getConsumedAt())->isPresent())
            ->map(function (MailboxChallengeEntity $row) {
                $updated = $row->toBuilder()
                    ->consumedAt(new DateTime())
                    ->build();
                $this->challenges->persist($updated);
                $this->logger->info('mailbox.challenge.consumed', [
                    'challenge_id' => $row->getId(),
                    'sent_to_hash' => $row->getSentToHash(),
                ]);

                return true;
            })
            ->orElse(false);
    }

    public function advanceMigrationToNewMailbox(string $challengeId, string $newEmail): MailboxChallengeIssueResult
    {
        return Optional::ofNullable($this->challenges->findById($challengeId))
            ->filter(fn (MailboxChallengeEntity $row) => !Optional::ofNullable($row->getConsumedAt())->isPresent())
            ->map(function (MailboxChallengeEntity $row) use ($newEmail, $challengeId) {
                $destination = strtolower(trim($newEmail));
                $sentToHash = hash('sha256', $destination);
                $since = (new DateTime())->modify('-1 hour');
                $alreadySent = $this->challenges->countSendsSince($sentToHash, $row->getPurpose(), $since);
                if ($alreadySent >= self::MAX_SENDS_PER_HOUR) {
                    $this->logger->info('mailbox.challenge.throttled', [
                        'challenge_id' => $challengeId,
                        'sent_to_hash' => $sentToHash,
                        'purpose' => $row->getPurpose(),
                    ]);

                    return new MailboxChallengeIssueResult($challengeId, $sentToHash, false);
                }

                $code = $this->generateCode();
                $now = new DateTime();
                $updated = $row->toBuilder()
                    ->codeHash($this->hashCode($code, $this->pepper()))
                    ->sentToHash($sentToHash)
                    ->newEmail($destination)
                    ->attempts(0)
                    ->sendCount($row->getSendCount() + 1)
                    ->stage(MailboxChallengePurpose::STAGE_PENDING_NEW)
                    ->expiresAt((clone $now)->modify('+' . self::TTL_SECONDS . ' seconds'))
                    ->build();
                $this->challenges->persist($updated);
                $this->deliver($destination, $code, $challengeId, $sentToHash, $row->getPurpose());

                return new MailboxChallengeIssueResult($challengeId, $sentToHash, true);
            })
            ->orElseGet(fn () => new MailboxChallengeIssueResult($challengeId, hash('sha256', strtolower(trim($newEmail))), false));
    }

    public function findById(string $challengeId): ?MailboxChallengeEntity
    {
        return $this->challenges->findById($challengeId);
    }

    public function isConsumedForUser(string $challengeId, string $idpUserId): bool
    {
        $now = new DateTime();

        return Optional::ofNullable($this->challenges->findById($challengeId))
            ->filter(fn (MailboxChallengeEntity $row) => $row->getIdpUserId() === $idpUserId)
            ->filter(fn (MailboxChallengeEntity $row) => Optional::ofNullable($row->getConsumedAt())->isPresent())
            ->filter(fn (MailboxChallengeEntity $row) => $row->getExpiresAt() >= $now)
            ->isPresent();
    }

    public function findLatestOpenByUserAndPurpose(string $idpUserId, string $purpose): ?MailboxChallengeEntity
    {
        return $this->challenges->findLatestOpenByUserAndPurpose($idpUserId, $purpose);
    }

    public function findOpenByMundanePurposeAndHash(int $mundaneId, string $purpose, string $sentToHash): ?MailboxChallengeEntity
    {
        return Optional::ofNullable($this->challenges->findOpenByMundanePurposeAndHash($mundaneId, $purpose, $sentToHash))
            ->filter(fn (MailboxChallengeEntity $row) => $row->getExpiresAt() >= new DateTime())
            ->orElse(null);
    }

    private function evaluate(MailboxChallengeEntity $row, string $code): MailboxChallengeCheckResult
    {
        return Optional::ofNullable($row->getConsumedAt())
            ->map(fn () => new MailboxChallengeCheckResult(
                $row->getAttempts() >= self::MAX_ATTEMPTS
                    ? MailboxChallengeCheckResult::LOCKED
                    : MailboxChallengeCheckResult::CONSUMED,
                $row,
            ))
            ->orElseGet(function () use ($row, $code) {
                if ($row->getExpiresAt() < new DateTime()) {
                    return new MailboxChallengeCheckResult(MailboxChallengeCheckResult::EXPIRED, $row);
                }

                $expected = $row->getCodeHash();
                $actual = $this->hashCode($code, $this->pepper());
                if (hash_equals($expected, $actual)) {
                    return new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK, $row);
                }

                $attempts = $row->getAttempts() + 1;
                $locked = $attempts >= self::MAX_ATTEMPTS;
                $updated = $row->toBuilder()
                    ->attempts($attempts)
                    ->consumedAt($locked ? new DateTime() : null)
                    ->build();
                $this->challenges->persist($updated);
                $this->logger->info('mailbox.challenge.check_failed', [
                    'challenge_id' => $row->getId(),
                    'sent_to_hash' => $row->getSentToHash(),
                    'attempts' => $attempts,
                ]);

                return new MailboxChallengeCheckResult(
                    $locked ? MailboxChallengeCheckResult::LOCKED : MailboxChallengeCheckResult::WRONG,
                    $updated,
                );
            });
    }

    private function deliver(string $destination, string $code, string $challengeId, string $sentToHash, string $purpose): void
    {
        $this->mail->send(
            $destination,
            'Your Amtgard verification code',
            "Your verification code is {$code}. It expires in 10 minutes.",
        );
        $this->logger->info('mailbox.challenge.issued', [
            'challenge_id' => $challengeId,
            'sent_to_hash' => $sentToHash,
            'purpose' => $purpose,
        ]);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $code, string $pepper): string
    {
        return hash_hmac('sha256', $code, $pepper);
    }

    private function pepper(): string
    {
        $pepper = (string) ($_ENV['MAILBOX_CODE_PEPPER'] ?? '');
        if ($pepper === '') {
            throw new \RuntimeException('MAILBOX_CODE_PEPPER is not set');
        }

        return $pepper;
    }

    private function assertKnownPurpose(string $purpose): void
    {
        if (!MailboxChallengePurpose::isKnown($purpose)) {
            throw new \InvalidArgumentException('unknown mailbox challenge purpose');
        }
    }
}
