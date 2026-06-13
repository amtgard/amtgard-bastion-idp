<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use PHPUnit\Framework\TestCase;

class UserOrkProfileRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'user_ork_profiles';
        $this->fields = [];
        foreach ([
            'user_id' => FieldType::INTEGER,
            'linked_via' => FieldType::STRING,
            'ork_token' => FieldType::STRING,
            'mundane_id' => FieldType::INTEGER,
            'username' => FieldType::STRING,
            'persona' => FieldType::STRING,
            'suspended' => FieldType::INTEGER,
            'suspended_at' => FieldType::DATETIME,
            'suspended_until' => FieldType::DATETIME,
            'email' => FieldType::STRING,
            'park_id' => FieldType::INTEGER,
            'park_name' => FieldType::STRING,
            'kingdom_id' => FieldType::INTEGER,
            'kingdom_name' => FieldType::STRING,
            'dues_through' => FieldType::DATETIME,
            'heraldry' => FieldType::STRING,
            'image' => FieldType::STRING,
            'created_at' => FieldType::DATETIME,
            'updated_at' => FieldType::DATETIME,
        ] as $name => $type) {
            $this->fields[$name] = FieldDefinition::builder()->name($name)->type($type)->build();
        }
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class UserOrkProfileRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new UserOrkProfileRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testFindByUserIdFetchesByUserId(): void
    {
        $profile = new UserOrkProfileEntity();
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('user_id', 10)->willReturn($profile);

        $this->assertSame($profile, $repository->findByUserId(10));
    }

    public function testSaveOrUpdateProfileCreatesNewProfileAndParsesDates(): void
    {
        $captured = null;
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->with(10)->willReturn(null);
        $repository->expects($this->once())->method('persist')->willReturnCallback(function (UserOrkProfileEntity $entity) use (&$captured): UserOrkProfileEntity {
            $captured = $entity;
            return $entity;
        });

        $repository->saveOrUpdateProfile($this->playerData(), [
            'ParkInfo' => ['ParkName' => 'Test Park'],
            'KingdomInfo' => ['KingdomName' => 'Test Kingdom'],
        ], 'ork-token', 10);

        $this->assertInstanceOf(UserOrkProfileEntity::class, $captured);
        $this->assertSame(10, $captured->getUserId());
        $this->assertSame(99, $captured->getMundaneId());
        $this->assertSame('ork-token', $captured->getOrkToken());
        $this->assertSame('Test Park', $captured->getParkName());
        $this->assertInstanceOf(\DateTime::class, $captured->getDuesThrough());
        $this->assertNull($captured->getSuspendedAt());
        $this->assertNull($captured->getSuspendedUntil());
    }

    public function testSaveOrUpdateProfileUpdatesExistingProfile(): void
    {
        $existing = new class extends UserOrkProfileEntity {
            public function toBuilder()
            {
                return UserOrkProfileEntity::builder()
                    ->userId(10)
                    ->orkToken('old-token')
                    ->mundaneId(88)
                    ->username('old')
                    ->persona('old')
                    ->suspended(0)
                    ->createdAt(new \DateTime('-1 day'))
                    ->updatedAt(new \DateTime('-1 day'));
            }
        };
        $captured = null;
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturn($existing);
        $repository->expects($this->once())->method('persist')->willReturnCallback(function (UserOrkProfileEntity $entity) use (&$captured): UserOrkProfileEntity {
            $captured = $entity;
            return $entity;
        });

        $repository->saveOrUpdateProfile($this->playerData(), null, 'new-token', 10);

        $this->assertSame(99, $captured->getMundaneId());
        $this->assertSame('new-token', $captured->getOrkToken());
        $this->assertNull($captured->getParkName());
        $this->assertNull($captured->getKingdomName());
    }

    public function testSaveOrUpdateProfileStoresNullForMissingParkAndKingdomIds(): void
    {
        $captured = null;
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->with(10)->willReturn(null);
        $repository->expects($this->once())->method('persist')->willReturnCallback(function (UserOrkProfileEntity $entity) use (&$captured): UserOrkProfileEntity {
            $captured = $entity;
            return $entity;
        });

        $playerData = $this->playerData();
        $playerData['ParkId'] = 0;
        $playerData['KingdomId'] = null;

        $repository->saveOrUpdateProfile($playerData, null, 'ork-token', 10);

        $this->assertNull($captured->getParkId());
        $this->assertNull($captured->getKingdomId());
        $this->assertNull($captured->getParkName());
        $this->assertNull($captured->getKingdomName());
    }

    public function testLinkExistingUserToMundaneIsNoOpWhenAlreadyLinked(): void
    {
        $existing = $this->createMock(UserOrkProfileEntity::class);
        $existing->method('getMundaneId')->willReturn(99);

        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->with(10)->willReturn($existing);
        $repository->expects($this->never())->method('persist');

        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');
    }

    public function testLinkExistingUserToMundaneThrowsConflictForDifferentMundane(): void
    {
        $existing = $this->createMock(UserOrkProfileEntity::class);
        $existing->method('getMundaneId')->willReturn(88);

        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturn($existing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflict');
        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');
    }

    public function testLinkExistingUserToMundaneCreatesPlaceholderWhenMissing(): void
    {
        $captured = null;
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturn(null);
        $repository->expects($this->once())->method('persist')->willReturnCallback(function (UserOrkProfileEntity $entity) use (&$captured): UserOrkProfileEntity {
            $captured = $entity;
            return $entity;
        });

        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');

        $this->assertSame(10, $captured->getUserId());
        $this->assertSame(99, $captured->getMundaneId());
        $this->assertSame('', $captured->getOrkToken());
        $this->assertSame('', $captured->getUsername());
    }

    public function testLinkExistingUserToMundaneTreatsDuplicateSamePairAsIdempotent(): void
    {
        $current = $this->createMock(UserOrkProfileEntity::class);
        $current->method('getMundaneId')->willReturn(99);
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturnOnConsecutiveCalls(null, $current);
        $repository->method('persist')->willThrowException(new \PDOException('Duplicate entry', 23000));

        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');

        $this->addToAssertionCount(1);
    }

    public function testLinkExistingUserToMundaneTranslatesDuplicateDifferentPairToConflict(): void
    {
        $current = $this->createMock(UserOrkProfileEntity::class);
        $current->method('getMundaneId')->willReturn(88);
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturnOnConsecutiveCalls(null, $current);
        $repository->method('persist')->willThrowException(new \PDOException('Duplicate entry', 23000));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflict');
        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');
    }

    public function testLinkExistingUserToMundaneTranslatesDuplicateMundaneToConflict(): void
    {
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturn(null);
        $repository->method('persist')->willThrowException(new \PDOException('Duplicate entry', 23000));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mundane_id=99');
        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');
    }

    public function testLinkExistingUserToMundaneRethrowsNonIntegrityPdoException(): void
    {
        $repository = $this->getMockBuilder(UserOrkProfileRepository::class)
            ->onlyMethods(['findByUserId', 'persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findByUserId')->willReturn(null);
        $repository->method('persist')->willThrowException(new \PDOException('connection lost', 40001));

        $this->expectException(\PDOException::class);
        $repository->linkExistingUserToMundane(10, 99, 'ork_handoff');
    }

    public function testUserOrkProfileEntityAccessorsRoundTripNullableFields(): void
    {
        $profile = new UserOrkProfileEntity();
        $suspendedAt = new \DateTime('2026-01-01');
        $suspendedUntil = new \DateTime('2026-02-01');
        $duesThrough = new \DateTime('2026-03-01');
        $createdAt = new \DateTime('2026-04-01');
        $updatedAt = new \DateTime('2026-05-01');

        $profile->setUserId(10);
        $profile->setOrkToken('token');
        $profile->setMundaneId(99);
        $profile->setUsername('player');
        $profile->setPersona('Sir Test');
        $profile->setSuspended(1);
        $profile->setSuspendedAt($suspendedAt);
        $profile->setSuspendedUntil($suspendedUntil);
        $profile->setEmail('player@example.com');
        $profile->setParkId(5);
        $profile->setParkName('Park');
        $profile->setKingdomId(7);
        $profile->setKingdomName('Kingdom');
        $profile->setDuesThrough($duesThrough);
        $profile->setHeraldry('heraldry.png');
        $profile->setImage('image.png');
        $profile->setCreatedAt($createdAt);
        $profile->setUpdatedAt($updatedAt);

        $this->assertSame(10, $profile->getUserId());
        $this->assertSame('token', $profile->getOrkToken());
        $this->assertSame(99, $profile->getMundaneId());
        $this->assertSame('player', $profile->getUsername());
        $this->assertSame('Sir Test', $profile->getPersona());
        $this->assertSame(1, $profile->getSuspended());
        $this->assertSame($suspendedAt, $profile->getSuspendedAt());
        $this->assertSame($suspendedUntil, $profile->getSuspendedUntil());
        $this->assertSame('player@example.com', $profile->getEmail());
        $this->assertSame(5, $profile->getParkId());
        $this->assertSame('Park', $profile->getParkName());
        $this->assertSame(7, $profile->getKingdomId());
        $this->assertSame('Kingdom', $profile->getKingdomName());
        $this->assertSame($duesThrough, $profile->getDuesThrough());
        $this->assertSame('heraldry.png', $profile->getHeraldry());
        $this->assertSame('image.png', $profile->getImage());
        $this->assertSame($createdAt, $profile->getCreatedAt());
        $this->assertSame($updatedAt, $profile->getUpdatedAt());
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('user_ork_profiles', UserOrkProfileRepository::getTableName());
        $this->assertSame(UserOrkProfileEntity::class, UserOrkProfileRepository::getEntityClass());
    }

    private function playerData(): array
    {
        return [
            'MundaneId' => '99',
            'UserName' => 'player',
            'Persona' => 'Sir Test',
            'Suspended' => '0',
            'Email' => 'player@example.com',
            'ParkId' => '5',
            'KingdomId' => '7',
            'Image' => 'image.png',
            'Heraldry' => 'heraldry.png',
            'SuspendedAt' => '0000-00-00',
            'SuspendedUntil' => 'not-a-date',
            'DuesThrough' => '2026-12-31',
        ];
    }
}
