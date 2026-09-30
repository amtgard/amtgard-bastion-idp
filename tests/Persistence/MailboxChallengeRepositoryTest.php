<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Tests\Support\MailboxChallengeTestSchema;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use DateTime;
use PHPUnit\Framework\TestCase;

final class MailboxChallengeRepositoryTest extends TestCase
{
    protected function setUp(): void
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

    public function testFindByIdFetchesPrimaryKey(): void
    {
        $row = MailboxChallengeEntity::builder()->id('abc')->purpose(MailboxChallengePurpose::CLAIM_IDP)->build();
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('id', 'abc')->willReturn($row);

        $this->assertSame($row, $repository->findById('abc'));
    }

    public function testFindByDestinationAndPurposeReturnsRows(): void
    {
        $row = MailboxChallengeEntity::builder()->id('abc')->purpose(MailboxChallengePurpose::CLAIM_IDP)->build();
        $fields = [];
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find');
        $second = MailboxChallengeEntity::builder()->id('def')->purpose(MailboxChallengePurpose::CLAIM_IDP)->build();
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, false);
        $repository->method('getCurrent')->willReturnOnConsecutiveCalls($row, $second);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertSame([$row, $second], $repository->findByDestinationAndPurpose('hash', MailboxChallengePurpose::CLAIM_IDP));
        $this->assertSame('hash', $fields['sent_to_hash']);
        $this->assertSame(MailboxChallengePurpose::CLAIM_IDP, $fields['purpose']);
    }

    public function testCountSendsSinceSumsMatchingRows(): void
    {
        $since = new DateTime('-1 hour');
        $recent = MailboxChallengeEntity::builder()
            ->id('a')
            ->sendCount(2)
            ->createdAt(new DateTime('-10 minutes'))
            ->build();
        $boundary = MailboxChallengeEntity::builder()
            ->id('edge')
            ->sendCount(3)
            ->createdAt($since)
            ->build();
        $zero = MailboxChallengeEntity::builder()
            ->id('zero')
            ->sendCount(0)
            ->createdAt(new DateTime('-5 minutes'))
            ->build();
        $negative = MailboxChallengeEntity::builder()
            ->id('neg')
            ->sendCount(-4)
            ->createdAt(new DateTime('-5 minutes'))
            ->build();
        $old = MailboxChallengeEntity::builder()
            ->id('b')
            ->sendCount(4)
            ->createdAt(new DateTime('-2 hours'))
            ->build();
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['findByDestinationAndPurpose'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByDestinationAndPurpose')->willReturn([$recent, $boundary, $zero, $negative, $old]);

        $this->assertSame(5, $repository->countSendsSince('hash', MailboxChallengePurpose::CLAIM_IDP, $since));
    }

    public function testFindLatestOpenByUserAndPurposeSkipsConsumedAndKeepsNewest(): void
    {
        $older = MailboxChallengeEntity::builder()
            ->id('old')
            ->createdAt(new DateTime('-5 minutes'))
            ->consumedAt(null)
            ->build();
        $consumed = MailboxChallengeEntity::builder()
            ->id('used')
            ->createdAt(new DateTime('-1 minute'))
            ->consumedAt(new DateTime())
            ->build();
        $newer = MailboxChallengeEntity::builder()
            ->id('new')
            ->createdAt(new DateTime())
            ->consumedAt(null)
            ->build();
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, true, false);
        $repository->method('getCurrent')->willReturnOnConsecutiveCalls($older, $consumed, $newer);

        $this->assertSame($newer, $repository->findLatestOpenByUserAndPurpose('user-1', MailboxChallengePurpose::MIGRATE_IDP_EMAIL));
    }

    public function testFindLatestOpenKeepsFirstWhenCreatedAtTied(): void
    {
        $tied = new DateTime();
        $first = MailboxChallengeEntity::builder()->id('first')->createdAt($tied)->consumedAt(null)->build();
        $second = MailboxChallengeEntity::builder()->id('second')->createdAt($tied)->consumedAt(null)->build();
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, false);
        $repository->method('getCurrent')->willReturnOnConsecutiveCalls($first, $second);

        $this->assertSame($first, $repository->findLatestOpenByUserAndPurpose('user-1', MailboxChallengePurpose::MIGRATE_IDP_EMAIL));
    }

    public function testFindOpenByMundanePurposeAndHashSkipsConsumed(): void
    {
        $consumed = MailboxChallengeEntity::builder()->id('used')->consumedAt(new DateTime())->build();
        $open = MailboxChallengeEntity::builder()->id('open')->consumedAt(null)->build();
        $repository = $this->getMockBuilder(MailboxChallengeRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, false);
        $repository->method('getCurrent')->willReturnOnConsecutiveCalls($consumed, $open);

        $this->assertSame($open, $repository->findOpenByMundanePurposeAndHash(9, MailboxChallengePurpose::CLAIM_IDP, 'hash'));
    }

    public function testMetadata(): void
    {
        $this->assertSame('mailbox_challenges', MailboxChallengeRepository::getTableName());
        $this->assertSame(MailboxChallengeEntity::class, MailboxChallengeRepository::getEntityClass());
    }
}
