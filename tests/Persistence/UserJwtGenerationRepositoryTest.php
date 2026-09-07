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
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserJwtGeneration;
use Amtgard\IdP\Persistence\Server\Repositories\UserJwtGenerationRepository;
use Amtgard\IdP\Utility\Pvh;
use PHPUnit\Framework\TestCase;

class UserJwtGenerationRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'user_jwt_generations';
        $this->fields = [
            'user_id' => FieldDefinition::builder()->name('user_id')->type(FieldType::INTEGER)->build(),
            'user_uuid' => FieldDefinition::builder()->name('user_uuid')->type(FieldType::STRING)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'aud' => FieldDefinition::builder()->name('aud')->type(FieldType::STRING)->build(),
            'pvh' => FieldDefinition::builder()->name('pvh')->type(FieldType::STRING)->build(),
            'prev_pvh' => FieldDefinition::builder()->name('prev_pvh')->type(FieldType::STRING)->build(),
            'policy_hash' => FieldDefinition::builder()->name('policy_hash')->type(FieldType::BINARY)->build(),
            'updated_at' => FieldDefinition::builder()->name('updated_at')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class UserJwtGenerationRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new UserJwtGenerationRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testFindByUserUuidAndAudReturnsRow(): void
    {
        $row = new class extends UserJwtGeneration {
        };

        $repository = $this->getMockBuilder(UserJwtGenerationRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(1);
        $repository->method('next')->willReturn(true);
        $repository->method('getCurrent')->willReturn($row);

        $this->assertSame($row, $repository->findByUserUuidAndAud('user-uuid', 'client-a'));
    }

    public function testFindByUserUuidAndAudReturnsNullWhenMissing(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(UserJwtGenerationRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(0);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertNull($repository->findByUserUuidAndAud('user-uuid', 'client-a'));
        $this->assertSame('user-uuid', $fields['user_uuid']);
        $this->assertSame('client-a', $fields['aud']);
    }

    public function testUpsertCreatesRowWhenMissing(): void
    {
        $hash = Pvh::policyHash('client-a', '["orn:a"]', '');
        $nowMs = 1_700_000_000_000;
        $persisted = null;

        $repository = $this->getMockBuilder(UserJwtGenerationRepository::class)
            ->onlyMethods(['clear', 'find', 'persist', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(0);
        $repository->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (UserJwtGeneration $row) use (&$persisted): UserJwtGeneration {
                $persisted = $row;
                return $row;
            });

        $result = $repository->upsert(7, 'user-uuid', 3, 'client-a', $hash, $nowMs);

        $this->assertInstanceOf(UserJwtGeneration::class, $persisted);
        $this->assertSame($persisted, $result);
        $this->assertSame(7, $persisted->getUserId());
        $this->assertSame('user-uuid', $persisted->getUserUuid());
        $this->assertSame('client-a', $persisted->getAud());
        $this->assertSame($hash, $persisted->getPolicyHash());
        $this->assertSame(Pvh::encode($nowMs, $hash), $persisted->getPvh());
        $this->assertNull($persisted->getPrevPvh());
    }

    public function testUpsertLeavesPvhWhenPolicyHashUnchanged(): void
    {
        $hash = Pvh::policyHash('client-a', '["orn:a"]', '');
        $encodedAtMs = 1_700_000_000_000;
        $nowMs = 1_800_000_000_000;
        $existingPvh = Pvh::encode($encodedAtMs, $hash);
        $existingPrevPvh = str_repeat('b', 44);

        $row = new class extends UserJwtGeneration {
            public string $pvh;
            public ?string $prevPvh = null;
            public string $policyHash;
            public ?string $updatedAt = null;

            public function getPvh(): string
            {
                return $this->pvh;
            }

            public function getPolicyHash(): string
            {
                return $this->policyHash;
            }
        };
        $row->pvh = $existingPvh;
        $row->prevPvh = $existingPrevPvh;
        $row->policyHash = $hash;

        $repository = $this->getMockBuilder(UserJwtGenerationRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', 'persist', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(1);
        $repository->expects($this->once())->method('next')->willReturn(true);
        $repository->expects($this->once())->method('getCurrent')->willReturn($row);
        $repository->expects($this->once())->method('persist')->with($row)->willReturn($row);

        $result = $repository->upsert(7, 'user-uuid', 3, 'client-a', $hash, $nowMs);

        $this->assertSame($row, $result);
        $this->assertSame($existingPvh, $row->pvh);
        $this->assertSame($existingPrevPvh, $row->prevPvh);
        $this->assertSame($hash, $row->policyHash);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row->updatedAt);
        $this->assertNotSame(Pvh::encode($nowMs, $hash), $row->pvh);
    }

    public function testUpsertRotatesPrevPvhWhenPolicyHashChanges(): void
    {
        $oldHash = Pvh::policyHash('client-a', '["orn:a"]', '');
        $newHash = Pvh::policyHash('client-a', '["orn:b"]', '');
        $nowMs = 1_800_000_000_000;
        $existingPvh = str_repeat('a', 44);

        $row = new class extends UserJwtGeneration {
            public string $pvh;
            public ?string $prevPvh = null;
            public string $policyHash;
            public ?string $updatedAt = null;

            public function getPvh(): string
            {
                return $this->pvh;
            }

            public function getPolicyHash(): string
            {
                return $this->policyHash;
            }
        };
        $row->pvh = $existingPvh;
        $row->prevPvh = null;
        $row->policyHash = $oldHash;

        $repository = $this->getMockBuilder(UserJwtGenerationRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', 'persist', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(1);
        $repository->expects($this->once())->method('next')->willReturn(true);
        $repository->expects($this->once())->method('getCurrent')->willReturn($row);
        $repository->expects($this->once())->method('persist')->with($row)->willReturn($row);

        $result = $repository->upsert(7, 'user-uuid', 3, 'client-a', $newHash, $nowMs);

        $this->assertSame($row, $result);
        $this->assertSame($existingPvh, $row->prevPvh);
        $this->assertSame(Pvh::encode($nowMs, $newHash), $row->pvh);
        $this->assertSame($newHash, $row->policyHash);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row->updatedAt);
    }
}
