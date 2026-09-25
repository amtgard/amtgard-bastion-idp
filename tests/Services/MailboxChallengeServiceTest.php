<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Tests\Support\MailboxChallengeTestSchema;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Tests\Support\RecordingOutboundMail;
use DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

require_once dirname(__DIR__) . '/Support/MailboxRandomIntOverride.php';

final class MailboxChallengeServiceTest extends TestCase
{
    /** @var array<string, MailboxChallengeEntity> */
    private array $rows = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $logs = [];
    private RecordingOutboundMail $mail;
    private MailboxChallengeService $service;

    protected function setUp(): void
    {
        $_ENV['MAILBOX_CODE_PEPPER'] = 'unit-test-pepper';
        $this->rows = [];
        $this->logs = [];
        $this->mail = new RecordingOutboundMail();
        $this->configureEntityManager();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logs[] = [$message, $context];
        });
        $this->service = new MailboxChallengeService($this->repository(), $this->mail, $logger);
        unset($GLOBALS['idp_mailbox_random_int']);
    }

    public function testIssueMailsSixDigitCodeAndStoresHmac(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'Player@Example.com', 'user-1', 44);

        $this->assertTrue($issued->mailed);
        $this->assertSame(hash('sha256', 'player@example.com'), $issued->sentToHash);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->mail->lastCode());
        $this->assertSame('player@example.com', $this->mail->messages[0]['to']);
        $row = $this->service->findById($issued->challengeId);
        $this->assertSame(hash_hmac('sha256', $this->mail->lastCode(), 'unit-test-pepper'), $row->getCodeHash());
        $this->assertSame(MailboxChallengePurpose::CLAIM_IDP, $row->getPurpose());
        $this->assertSame('user-1', $row->getIdpUserId());
        $this->assertSame(44, $row->getMundaneId());
    }

    public function testWrongCodeIncrementsAttempts(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $result = $this->service->check($issued->challengeId, '000000');

        $this->assertSame(MailboxChallengeCheckResult::WRONG, $result->reason);
        $this->assertSame(1, $this->rows[$issued->challengeId]->getAttempts());
        $this->assertFalse($this->service->check($issued->challengeId, '000000')->ok());
        $this->assertSame(2, $this->rows[$issued->challengeId]->getAttempts());
    }

    public function testSixthWrongCodeConsumesTheRow(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->check($issued->challengeId, '111111')->reason);
        }
        $locked = $this->service->check($issued->challengeId, '111111');

        $this->assertSame(MailboxChallengeCheckResult::LOCKED, $locked->reason);
        $this->assertNotNull($this->rows[$issued->challengeId]->getConsumedAt());
        $this->assertSame(MailboxChallengeCheckResult::LOCKED, $this->service->check($issued->challengeId, $this->mail->lastCode())->reason);
    }

    public function testResendInvalidatesPreviousHash(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $first = $this->mail->lastCode();
        $this->service->resend($issued->challengeId, 'a@example.com');
        $second = $this->mail->lastCode();

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $this->rows[$issued->challengeId]->getSendCount());
        $this->assertSame(0, $this->rows[$issued->challengeId]->getAttempts());
        $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->check($issued->challengeId, $first)->reason);
        $this->assertTrue($this->service->check($issued->challengeId, $second)->ok());
    }

    public function testExpiredRowFailsClosed(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $row = $this->rows[$issued->challengeId];
        $this->rows[$issued->challengeId] = $row->toBuilder()
            ->expiresAt(new DateTime('-1 minute'))
            ->build();

        $this->assertSame(MailboxChallengeCheckResult::EXPIRED, $this->service->check($issued->challengeId, $this->mail->lastCode())->reason);
    }

    public function testCodeForAnotherChallengeIdFails(): void
    {
        $first = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $code = $this->mail->lastCode();
        $second = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'b@example.com');

        $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->check($second->challengeId, $code)->reason);
        $this->assertTrue($this->service->check($first->challengeId, $code)->ok());
    }

    public function testUnknownChallengeIdFails(): void
    {
        $this->assertSame(MailboxChallengeCheckResult::UNKNOWN, $this->service->check('missing', '123456')->reason);
    }

    public function testPepperMismatchFails(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $code = $this->mail->lastCode();
        $_ENV['MAILBOX_CODE_PEPPER'] = 'other-pepper';

        $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->check($issued->challengeId, $code)->reason);
    }

    public function testCorrectCodeDoesNotConsumeUntilAsked(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $this->assertTrue($this->service->check($issued->challengeId, $this->mail->lastCode())->ok());
        $this->assertNull($this->rows[$issued->challengeId]->getConsumedAt());
        $this->assertTrue($this->service->consume($issued->challengeId));
        $this->assertNotNull($this->rows[$issued->challengeId]->getConsumedAt());
        $this->assertFalse($this->service->consume($issued->challengeId));
        $this->assertSame(MailboxChallengeCheckResult::CONSUMED, $this->service->check($issued->challengeId, $this->mail->lastCode())->reason);
    }

    public function testReserveDoesNotSendMail(): void
    {
        $row = $this->service->reserve(MailboxChallengePurpose::CLAIM_ORK, 'idp-user');

        $this->assertSame([], $this->mail->messages);
        $this->assertSame(MailboxChallengePurpose::CLAIM_ORK, $row->getPurpose());
        $this->assertSame('idp-user', $row->getIdpUserId());
        $this->assertSame('', $row->getCodeHash());
        $this->assertSame(0, $row->getSendCount());
    }

    public function testThrottleDoesNotMailASixthSend(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'same@example.com')->mailed);
        }
        $sixth = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'same@example.com');

        $this->assertFalse($sixth->mailed);
        $this->assertCount(5, $this->mail->messages);
    }

    public function testResendThrottleAndUnknownResend(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'same@example.com');
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($this->service->resend($issued->challengeId, 'same@example.com')->mailed);
        }
        $this->assertFalse($this->service->resend($issued->challengeId, 'same@example.com')->mailed);
        $this->assertFalse($this->service->resend('missing', 'same@example.com')->mailed);
    }

    public function testAdvanceMigrationReplacesHashAndStage(): void
    {
        $issued = $this->service->issue(
            MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            'old@example.com',
            'user-1',
            null,
            'new@example.com',
            MailboxChallengePurpose::STAGE_PENDING_CURRENT,
        );
        $oldCode = $this->mail->lastCode();
        $advanced = $this->service->advanceMigrationToNewMailbox($issued->challengeId, 'new@example.com');
        $newCode = $this->mail->lastCode();

        $this->assertTrue($advanced->mailed);
        $this->assertNotSame($oldCode, $newCode);
        $this->assertSame(MailboxChallengePurpose::STAGE_PENDING_NEW, $this->rows[$issued->challengeId]->getStage());
        $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->check($issued->challengeId, $oldCode)->reason);
        $this->assertTrue($this->service->check($issued->challengeId, $newCode)->ok());
    }

    public function testIsConsumedForUserRequiresMatchAndFreshExpiry(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com', 'user-1');
        $this->assertFalse($this->service->isConsumedForUser($issued->challengeId, 'user-1'));
        $this->service->consume($issued->challengeId);
        $this->assertTrue($this->service->isConsumedForUser($issued->challengeId, 'user-1'));
        $this->assertFalse($this->service->isConsumedForUser($issued->challengeId, 'other'));

        $this->rows[$issued->challengeId] = $this->rows[$issued->challengeId]->toBuilder()
            ->expiresAt(new DateTime('-1 minute'))
            ->build();
        $this->assertFalse($this->service->isConsumedForUser($issued->challengeId, 'user-1'));
    }

    public function testFindOpenByMundaneFiltersExpired(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com', null, 9);
        $hash = hash('sha256', 'a@example.com');
        $this->assertSame($issued->challengeId, $this->service->findOpenByMundanePurposeAndHash(9, MailboxChallengePurpose::CLAIM_IDP, $hash)?->getId());

        $this->rows[$issued->challengeId] = $this->rows[$issued->challengeId]->toBuilder()
            ->expiresAt(new DateTime('-1 minute'))
            ->build();
        $this->assertNull($this->service->findOpenByMundanePurposeAndHash(9, MailboxChallengePurpose::CLAIM_IDP, $hash));
    }

    public function testUnknownPurposeAndMissingPepperFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->issue('not-a-purpose', 'a@example.com');
    }

    public function testMissingPepperThrows(): void
    {
        unset($_ENV['MAILBOX_CODE_PEPPER']);
        $this->expectException(\RuntimeException::class);
        $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
    }

    public function testPurposeCatalog(): void
    {
        $this->assertTrue(MailboxChallengePurpose::isKnown(MailboxChallengePurpose::CONFIRM_FIRST_EMAIL));
        $this->assertFalse(MailboxChallengePurpose::isKnown('nope'));
        $this->assertContains(MailboxChallengePurpose::MIGRATE_ORK_EMAIL, MailboxChallengePurpose::all());
    }

    public function testAdvanceAndResendOnConsumedOrUnknown(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'old@example.com', 'user-1', null, 'new@example.com');
        $this->service->consume($issued->challengeId);
        $this->assertFalse($this->service->advanceMigrationToNewMailbox($issued->challengeId, 'new@example.com')->mailed);
        $this->assertFalse($this->service->advanceMigrationToNewMailbox('missing', 'new@example.com')->mailed);
        $this->assertFalse($this->service->resend($issued->challengeId, 'old@example.com')->mailed);
    }

    public function testFindLatestOpenDelegates(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'old@example.com', 'user-1');
        $found = $this->service->findLatestOpenByUserAndPurpose('user-1', MailboxChallengePurpose::MIGRATE_IDP_EMAIL);
        $this->assertSame($issued->challengeId, $found?->getId());
    }

    public function testIssueTrimsAndLowercasesDestination(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, '  Player@Example.com  ');

        $this->assertSame(hash('sha256', 'player@example.com'), $issued->sentToHash);
        $this->assertSame('player@example.com', $this->mail->messages[0]['to']);
        $this->assertLogContext('mailbox.challenge.issued', [
            'challenge_id' => $issued->challengeId,
            'sent_to_hash' => $issued->sentToHash,
            'purpose' => MailboxChallengePurpose::CLAIM_IDP,
        ]);
    }

    public function testIssueReusesProvidedChallengeIdAndRejectsBlank(): void
    {
        $named = $this->service->issue(
            MailboxChallengePurpose::CLAIM_IDP,
            'a@example.com',
            null,
            null,
            null,
            null,
            'named-challenge',
        );
        $blank = $this->service->issue(
            MailboxChallengePurpose::CLAIM_ORK,
            'b@example.com',
            null,
            null,
            null,
            null,
            '',
        );

        $this->assertSame('named-challenge', $named->challengeId);
        $this->assertNotSame('', $blank->challengeId);
        $this->assertTrue(Uuid::isValid($blank->challengeId));
    }

    public function testIssueTtlIsTenMinutesAndReserveHashUsesUser(): void
    {
        $before = new DateTime();
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $after = new DateTime();
        $expires = $this->rows[$issued->challengeId]->getExpiresAt();
        $this->assertGreaterThanOrEqual(
            (clone $before)->modify('+' . MailboxChallengeService::TTL_SECONDS . ' seconds')->getTimestamp(),
            $expires->getTimestamp(),
        );
        $this->assertLessThanOrEqual(
            (clone $after)->modify('+' . MailboxChallengeService::TTL_SECONDS . ' seconds')->getTimestamp(),
            $expires->getTimestamp(),
        );

        $reserved = $this->service->reserve(MailboxChallengePurpose::CLAIM_ORK, 'idp-user-9');
        $this->assertArrayHasKey($reserved->getId(), $this->rows);
        $this->assertSame(hash('sha256', 'reserved:idp-user-9'), $reserved->getSentToHash());
        $this->assertSame(0, $reserved->getSendCount());
        $this->assertSame(0, $reserved->getAttempts());
        $this->assertSame(
            MailboxChallengeService::RESERVE_TTL_SECONDS,
            $reserved->getExpiresAt()->getTimestamp() - $reserved->getCreatedAt()->getTimestamp(),
        );
        $this->assertSame(
            MailboxChallengeService::TTL_SECONDS,
            $this->rows[$issued->challengeId]->getExpiresAt()->getTimestamp()
                - $this->rows[$issued->challengeId]->getCreatedAt()->getTimestamp(),
        );
        $this->assertLogContext('mailbox.challenge.reserved', [
            'challenge_id' => $reserved->getId(),
            'sent_to_hash' => $reserved->getSentToHash(),
            'purpose' => MailboxChallengePurpose::CLAIM_ORK,
        ]);
    }

    public function testThrottleLogsExistingChallengeId(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'same@example.com');
        }
        $sixth = $this->service->issue(
            MailboxChallengePurpose::CLAIM_IDP,
            'same@example.com',
            null,
            null,
            null,
            null,
            'reuse-me',
        );

        $this->assertFalse($sixth->mailed);
        $this->assertSame('reuse-me', $sixth->challengeId);
        $this->assertLogContext('mailbox.challenge.throttled', [
            'challenge_id' => 'reuse-me',
            'sent_to_hash' => hash('sha256', 'same@example.com'),
            'purpose' => MailboxChallengePurpose::CLAIM_IDP,
        ]);
    }

    public function testResendAndAdvanceNormalizeAndLog(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'a@example.com');
        $resent = $this->service->resend($issued->challengeId, '  A@Example.com ');
        $this->assertTrue($resent->mailed);
        $this->assertSame(hash('sha256', 'a@example.com'), $resent->sentToHash);
        $this->assertSame(2, $this->rows[$issued->challengeId]->getSendCount());
        $this->assertSame(0, $this->rows[$issued->challengeId]->getAttempts());
        $this->assertSame(
            MailboxChallengeService::TTL_SECONDS,
            $this->rows[$issued->challengeId]->getExpiresAt()->getTimestamp()
                - $this->rows[$issued->challengeId]->getCreatedAt()->getTimestamp(),
        );

        $migrate = $this->service->issue(
            MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            'old@example.com',
            'user-1',
            null,
            'new@example.com',
            MailboxChallengePurpose::STAGE_PENDING_CURRENT,
        );
        $advanced = $this->service->advanceMigrationToNewMailbox($migrate->challengeId, '  New@Example.com ');
        $this->assertTrue($advanced->mailed);
        $this->assertSame(hash('sha256', 'new@example.com'), $advanced->sentToHash);
        $this->assertSame(2, $this->rows[$migrate->challengeId]->getSendCount());
        $this->assertSame(0, $this->rows[$migrate->challengeId]->getAttempts());
        $this->assertSame('new@example.com', $this->rows[$migrate->challengeId]->getNewEmail());
    }

    public function testConsumeAndWrongCodeLogDestinationHashNeverRawValues(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'secret@example.com');
        $this->service->check($issued->challengeId, '000000');
        $this->assertLogContext('mailbox.challenge.check_failed', [
            'challenge_id' => $issued->challengeId,
            'sent_to_hash' => hash('sha256', 'secret@example.com'),
            'attempts' => 1,
        ]);
        $this->assertTrue($this->service->consume($issued->challengeId));
        $this->assertLogContext('mailbox.challenge.consumed', [
            'challenge_id' => $issued->challengeId,
            'sent_to_hash' => hash('sha256', 'secret@example.com'),
        ]);
        foreach ($this->logs as [$message, $context]) {
            $this->assertStringNotContainsString('secret@example.com', $message);
            $this->assertStringNotContainsString('000000', json_encode($context));
            $this->assertArrayNotHasKey('email', $context);
            $this->assertArrayNotHasKey('code', $context);
        }
    }

    public function testThrottleAdvanceAndResendLogPurpose(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'old@example.com', 'user-1', null, 'new@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'new@example.com', 'other-' . $i);
        }
        $throttledAdvance = $this->service->advanceMigrationToNewMailbox($issued->challengeId, 'new@example.com');
        $this->assertFalse($throttledAdvance->mailed);
        $this->assertSame($issued->challengeId, $throttledAdvance->challengeId);
        $this->assertLogContext('mailbox.challenge.throttled', [
            'challenge_id' => $issued->challengeId,
            'sent_to_hash' => hash('sha256', 'new@example.com'),
            'purpose' => MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
        ]);

        $claim = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'resend@example.com');
        for ($i = 0; $i < 4; $i++) {
            $this->service->resend($claim->challengeId, 'resend@example.com');
        }
        $throttledResend = $this->service->resend($claim->challengeId, 'resend@example.com');
        $this->assertFalse($throttledResend->mailed);
        $this->assertSame($claim->challengeId, $throttledResend->challengeId);
        $this->assertInstanceOf(\Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult::class, $throttledResend);
        $this->assertLogContext('mailbox.challenge.throttled', [
            'challenge_id' => $claim->challengeId,
            'sent_to_hash' => hash('sha256', 'resend@example.com'),
            'purpose' => MailboxChallengePurpose::CLAIM_IDP,
        ]);
    }

    public function testReserveUnknownPurposeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->reserve('not-a-purpose', 'idp-user');
    }

    public function testAdvanceUnknownNormalizesDestinationHint(): void
    {
        $result = $this->service->advanceMigrationToNewMailbox('missing', '  Mix@Ed.com ');
        $this->assertFalse($result->mailed);
        $this->assertSame(hash('sha256', 'mix@ed.com'), $result->sentToHash);
    }

    public function testGeneratedCodeIsZeroPaddedSixDigits(): void
    {
        $seenMin = null;
        $seenMax = null;
        $GLOBALS['idp_mailbox_random_int'] = static function (int $min, int $max) use (&$seenMin, &$seenMax): int {
            $seenMin = $min;
            $seenMax = $max;

            return 42;
        };
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'pad@example.com');

        $this->assertSame(0, $seenMin);
        $this->assertSame(999999, $seenMax);
        $this->assertSame('000042', $this->mail->lastCode());
        $this->assertSame(
            hash_hmac('sha256', '000042', 'unit-test-pepper'),
            $this->rows[$issued->challengeId]->getCodeHash(),
        );
    }

    public function testPepperCastsStringableEnv(): void
    {
        $_ENV['MAILBOX_CODE_PEPPER'] = new class {
            public function __toString(): string
            {
                return 'unit-test-pepper';
            }
        };
        $issued = $this->service->issue(MailboxChallengePurpose::CLAIM_IDP, 'cast@example.com');
        $this->assertTrue($issued->mailed);
        $this->assertSame(
            hash_hmac('sha256', $this->mail->lastCode(), 'unit-test-pepper'),
            $this->rows[$issued->challengeId]->getCodeHash(),
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertLogContext(string $message, array $expected): void
    {
        foreach ($this->logs as [$loggedMessage, $context]) {
            if ($loggedMessage === $message && $context == $expected) {
                $this->assertSame($expected, $context);

                return;
            }
        }

        $this->fail('Missing log ' . $message . ' with ' . json_encode($expected));
    }

    public function testThrottleAdvanceMigration(): void
    {
        $issued = $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'old@example.com', 'user-1', null, 'new@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->service->issue(MailboxChallengePurpose::MIGRATE_IDP_EMAIL, 'new@example.com', 'other-' . $i);
        }
        $this->assertFalse($this->service->advanceMigrationToNewMailbox($issued->challengeId, 'new@example.com')->mailed);
    }

    private function repository(): MailboxChallengeRepository
    {
        $repository = $this->createMock(MailboxChallengeRepository::class);
        $repository->method('findById')->willReturnCallback(fn (string $id) => $this->rows[$id] ?? null);
        $repository->method('persist')->willReturnCallback(function (MailboxChallengeEntity $row) {
            $this->rows[$row->getId()] = $row;

            return $row;
        });
        $repository->method('countSendsSince')->willReturnCallback(function (string $hash, string $purpose, $since) {
            $total = 0;
            foreach ($this->rows as $row) {
                if ($row->getSentToHash() === $hash && $row->getPurpose() === $purpose && $row->getCreatedAt() >= $since) {
                    $total += $row->getSendCount();
                }
            }

            return $total;
        });
        $repository->method('findLatestOpenByUserAndPurpose')->willReturnCallback(function (string $idpUserId, string $purpose) {
            $latest = null;
            foreach ($this->rows as $row) {
                if ($row->getIdpUserId() === $idpUserId && $row->getPurpose() === $purpose && $row->getConsumedAt() === null) {
                    $latest = $row;
                }
            }

            return $latest;
        });
        $repository->method('findOpenByMundanePurposeAndHash')->willReturnCallback(function (int $mundaneId, string $purpose, string $hash) {
            foreach ($this->rows as $row) {
                if ($row->getMundaneId() === $mundaneId && $row->getPurpose() === $purpose && $row->getSentToHash() === $hash && $row->getConsumedAt() === null) {
                    return $row;
                }
            }

            return null;
        });

        return $repository;
    }

    private function configureEntityManager(): void
    {
        $policy = $this->createMock(DataAccessPolicy::class);
        $policy->method('applyTableSchemaPolicy')->willReturn(new MailboxChallengeTestSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($policy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }
}
