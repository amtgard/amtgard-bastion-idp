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
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserClientAuthorization;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use PHPUnit\Framework\TestCase;

class UserClientAuthorizationTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'user_client_authorizations';
        $this->fields = [
            'user_identifier' => FieldDefinition::builder()->name('user_identifier')->type(FieldType::STRING)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'created_at' => FieldDefinition::builder()->name('created_at')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class UserClientAuthorizationRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $database = $this->createMock(Database::class);
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $repositoryPolicy = $this->createMock(RepositoryPolicy::class);
        $tableSchema = new UserClientAuthorizationTableSchema();

        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn($tableSchema);

        $entityManager = EntityManager::builder()
            ->database($database)
            ->dataAccessPolicy($dataAccessPolicy)
            ->repositoryPolicy($repositoryPolicy)
            ->build();

        EntityManager::configure($entityManager, true);
    }

    public function testHasAuthorizationFindsByUserAndClient(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find')->willReturn(1);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertTrue($repository->hasAuthorization('user-123', 456));
        $this->assertSame('user-123', $fields['user_identifier']);
        $this->assertSame(456, $fields['client_id']);
    }

    public function testHasAuthorizationReturnsFalseWhenMissing(): void
    {
        $repository = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(0);

        $this->assertFalse($repository->hasAuthorization('user-123', 456));
    }

    public function testAuthorizePersistsEntityWithCreatedAt(): void
    {
        $captured = null;

        $repository = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['persist', 'hasAuthorization'])
            ->disableOriginalConstructor()
            ->getMock();

        $repository->expects($this->once())
            ->method('hasAuthorization')
            ->with('user-123', 456)
            ->willReturn(false);

        $repository->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (UserClientAuthorization $entity) use (&$captured) {
                $captured = $entity;
                return $entity;
            });

        $repository->authorize('user-123', 456);

        $this->assertInstanceOf(UserClientAuthorization::class, $captured);
        $this->assertSame('user-123', $captured->getUserIdentifier());
        $this->assertSame(456, $captured->getClientDbId());
        $this->assertInstanceOf(\DateTimeInterface::class, $captured->getCreatedAt());
    }

    public function testAuthorizeSkipsPersistWhenAuthorizationAlreadyExists(): void
    {
        $repository = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['persist', 'hasAuthorization'])
            ->disableOriginalConstructor()
            ->getMock();

        $repository->expects($this->once())
            ->method('hasAuthorization')
            ->with('user-123', 456)
            ->willReturn(true);

        $repository->expects($this->never())
            ->method('persist');

        $repository->authorize('user-123', 456);
    }

    public function testRevokeAuthorizationExecutesDeleteQuery(): void
    {
        $fields = [];
        $repository = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())
            ->method('query')
            ->with('DELETE FROM user_client_authorizations WHERE user_identifier = :user_identifier AND client_id = :client_id');
        $repository->expects($this->once())->method('execute');
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $repository->revokeAuthorization('user-123', 456);

        $this->assertSame('user-123', $fields['user_identifier']);
        $this->assertSame(456, $fields['client_id']);
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('user_client_authorizations', UserClientAuthorizationRepository::getTableName());
        $this->assertSame(UserClientAuthorization::class, UserClientAuthorizationRepository::getEntityClass());
    }
}
