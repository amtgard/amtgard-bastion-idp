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
use Amtgard\IdP\Persistence\Server\Entities\Repository\ClientAccess;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use PHPUnit\Framework\TestCase;

class ClientAccessTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'client_access';
        $this->fields = [
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'user_id' => FieldDefinition::builder()->name('user_id')->type(FieldType::INTEGER)->build(),
            'created_at' => FieldDefinition::builder()->name('created_at')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class ClientAccessRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new ClientAccessTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testHasAccessFindsByClientAndUser(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find')->willReturn(1);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertTrue($repository->hasAccess(9, 3));
        $this->assertSame(9, $fields['client_id']);
        $this->assertSame(3, $fields['user_id']);
    }

    public function testUserHasAnyAccessFindsByUser(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(2);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertTrue($repository->userHasAnyAccess(3));
        $this->assertSame(3, $fields['user_id']);
    }

    public function testFindByClientIdReturnsCurrentRows(): void
    {
        $row = ClientAccess::builder()->clientDbId(9)->userId(3)->build();
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, false);
        $repository->method('getCurrent')->willReturn($row);

        $this->assertSame([$row], $repository->findByClientId(9));
    }

    public function testGrantPersistsWhenMissing(): void
    {
        $captured = null;
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['persist', 'hasAccess'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('hasAccess')->with(9, 3)->willReturn(false);
        $repository->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (ClientAccess $entity) use (&$captured) {
                $captured = $entity;
                return $entity;
            });

        $repository->grant(9, 3);

        $this->assertSame(9, $captured->getClientDbId());
        $this->assertSame(3, $captured->getUserId());
        $this->assertInstanceOf(\DateTimeInterface::class, $captured->getCreatedAt());
    }

    public function testGrantSkipsWhenAlreadyPresent(): void
    {
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['persist', 'hasAccess'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('hasAccess')->willReturn(true);
        $repository->expects($this->never())->method('persist');

        $repository->grant(9, 3);
    }

    public function testFindByUserIdReturnsCurrentRows(): void
    {
        $row = ClientAccess::builder()->clientDbId(9)->userId(3)->build();
        $fields = [];
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, false);
        $repository->method('getCurrent')->willReturn($row);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertSame([$row], $repository->findByUserId(3));
        $this->assertSame(3, $fields['user_id']);
    }

    public function testRevokeDeletesMatchingRow(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(ClientAccessRepository::class)
            ->onlyMethods(['clear', 'delete', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('delete')->with(null);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $repository->revoke(9, 3);

        $this->assertSame(9, $fields['client_id']);
        $this->assertSame(3, $fields['user_id']);
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('client_access', ClientAccessRepository::getTableName());
        $this->assertSame(ClientAccess::class, ClientAccessRepository::getEntityClass());
    }
}
