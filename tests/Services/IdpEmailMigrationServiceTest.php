<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\IdpEmailMigrationService;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Tests\Controllers\TestUserEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__) . '/Controllers/AuthControllerTest.php';

final class IdpEmailMigrationServiceTest extends TestCase
{
    private MailboxChallengeService $challenges;
    private UserRepository $users;
    private IdpEmailMigrationService $service;
    private UserEntity $user;

    protected function setUp(): void
    {
        $this->challenges = $this->createMock(MailboxChallengeService::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->service = new IdpEmailMigrationService(
            $this->challenges,
            $this->users,
            $this->createMock(LoggerInterface::class),
        );
        $this->user = new TestUserEntity('uuid-1', 'old@example.com', 'Player');
    }

    public function testStartRejectsInvalidEmail(): void
    {
        $this->challenges->expects($this->never())->method('issue');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->start($this->user, 'not-an-email');
    }

    public function testStartIssuesCodeToCurrentMailbox(): void
    {
        $this->challenges->expects($this->once())
            ->method('issue')
            ->with(
                MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
                'old@example.com',
                'uuid-1',
                null,
                'new@example.com',
                MailboxChallengePurpose::STAGE_PENDING_CURRENT,
            )
            ->willReturn(new MailboxChallengeIssueResult('chal-1', 'hash', true));

        $result = $this->service->start($this->user, '  New@Example.com ');
        $this->assertSame('chal-1', $result->challengeId);
    }

    public function testConfirmWithoutOpenRowDoesNotChangeEmail(): void
    {
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn(null);
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertSame(MailboxChallengeCheckResult::UNKNOWN, $this->service->confirm($this->user, '123456')->reason);
    }

    public function testConfirmWithoutMatchingStageDoesNotAdvance(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_NEW);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->challenges->expects($this->never())->method('check');
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertSame(MailboxChallengeCheckResult::UNKNOWN, $this->service->confirm($this->user, '123456')->reason);
    }

    public function testConfirmWrongCodeDoesNotAdvance(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_CURRENT);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::WRONG, $row));
        $this->challenges->expects($this->never())->method('advanceMigrationToNewMailbox');
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertSame(MailboxChallengeCheckResult::WRONG, $this->service->confirm($this->user, '000000')->reason);
    }

    public function testConfirmSuccessSendsSecondCodeAndDoesNotUpdateEmail(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_CURRENT);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->challenges->method('check')->with('chal-1', '123456')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK, $row));
        $this->challenges->expects($this->once())->method('advanceMigrationToNewMailbox')->with('chal-1', 'new@example.com');
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertTrue($this->service->confirm($this->user, '123456')->ok());
    }

    public function testCommitWithoutSecondStageDoesNotUpdateEmail(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_CURRENT);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertSame(MailboxChallengeCheckResult::UNKNOWN, $this->service->commit($this->user, '123456')->reason);
    }

    public function testCommitWrongCodeDoesNotUpdateEmail(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_NEW);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::LOCKED, $row));
        $this->users->expects($this->never())->method('updateEmail');
        $this->assertSame(MailboxChallengeCheckResult::LOCKED, $this->service->commit($this->user, '000000')->reason);
    }

    public function testCommitSuccessUpdatesEmail(): void
    {
        $row = $this->challenge(MailboxChallengePurpose::STAGE_PENDING_NEW);
        $this->challenges->method('findLatestOpenByUserAndPurpose')->willReturn($row);
        $this->challenges->method('check')->with('chal-1', '654321')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK, $row));
        $this->challenges->expects($this->once())->method('consume')->with('chal-1');
        $this->users->expects($this->once())->method('updateEmail')->with($this->user, 'new@example.com');
        $this->assertTrue($this->service->commit($this->user, '654321')->ok());
    }

    private function challenge(string $stage): MailboxChallengeEntity
    {
        return new \Amtgard\IdP\Tests\Support\TestMailboxChallengeEntity(
            testPurpose: MailboxChallengePurpose::MIGRATE_IDP_EMAIL,
            testStage: $stage,
        );
    }
}
