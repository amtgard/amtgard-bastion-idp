<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;
use Amtgard\IdP\Persistence\Server\Entities\Repository\UserLoginClient;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use PHPUnit\Framework\TestCase;

class UserLoginClientRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'user_login_client';
        $this->fields = [
            'user_id' => FieldDefinition::builder()->name('user_id')->type(FieldType::INTEGER)->build(),
            'login_id' => FieldDefinition::builder()->name('login_id')->type(FieldType::INTEGER)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'metadata' => FieldDefinition::builder()->name('metadata')->type(FieldType::STRING)->build(),
            'encoding' => FieldDefinition::builder()->name('encoding')->type(FieldType::STRING)->build(),
            'updated_at' => FieldDefinition::builder()->name('updated_at')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class UserLoginClientRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new UserLoginClientRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testGetMetadataReturnsDecodedJsonRow(): void
    {
        $row = new class extends UserLoginClient {
            public function getMetadata(): string { return '{"role":"editor"}'; }
            public function getEncoding(): string { return ClientMetadataValidator::ENCODING_JSON; }
        };

        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(1);
        $repository->method('getCurrent')->willReturn($row);

        $result = $repository->getMetadata(2, 3);

        $this->assertSame(['role' => 'editor'], $result['metadata']);
        $this->assertSame(ClientMetadataValidator::ENCODING_JSON, $result['encoding']);
    }

    public function testGetMetadataForJwtReturnsOpaqueBase64String(): void
    {
        $row = new class extends UserLoginClient {
            public function getMetadata(): string { return 'abc123'; }
            public function getEncoding(): string { return ClientMetadataValidator::ENCODING_BASE64; }
        };

        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(1);
        $repository->method('getCurrent')->willReturn($row);

        $this->assertSame('abc123', $repository->getMetadataForJwt(2, 3));
    }

    public function testGetMetadataReturnsNullWhenRowMissing(): void
    {
        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(0);

        $this->assertNull($repository->getMetadata(2, 3));
    }

    public function testGetMetadataReturnsOpaqueBase64Row(): void
    {
        $row = new class extends UserLoginClient {
            public function getMetadata(): string { return 'opaque'; }
            public function getEncoding(): string { return ClientMetadataValidator::ENCODING_BASE64; }
        };
        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(1);
        $repository->method('getCurrent')->willReturn($row);

        $this->assertSame([
            'metadata' => 'opaque',
            'encoding' => ClientMetadataValidator::ENCODING_BASE64,
        ], $repository->getMetadata(2, 3));
    }

    public function testGetMetadataForJwtReturnsDecodedJsonObject(): void
    {
        $row = new class extends UserLoginClient {
            public function getMetadata(): string { return '{"tier":"gold"}'; }
            public function getEncoding(): string { return ClientMetadataValidator::ENCODING_JSON; }
        };
        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'getCurrent', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('find')->willReturn(1);
        $repository->method('getCurrent')->willReturn($row);

        $this->assertSame(['tier' => 'gold'], $repository->getMetadataForJwt(2, 3));
    }

    public function testDeleteMetadataExecutesDelete(): void
    {
        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('query');
        $repository->expects($this->once())->method('execute');

        $this->assertTrue($repository->deleteMetadata(2, 3));
    }

    public function testUpsertMetadataUpdatesExistingRow(): void
    {
        $row = new class extends RepositoryEntity {
            public ?string $metadata = null;
            public ?string $encoding = null;
            public ?\DateTimeImmutable $updated_at = null;
        };
        $fields = [];

        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'getCurrent', 'persist', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find')->willReturn(1);
        $repository->expects($this->once())->method('getCurrent')->willReturn($row);
        $repository->expects($this->once())->method('persist')->with($row)->willReturn($row);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $repository->upsertMetadata(1, 2, 3, '{"theme":"dark"}', ClientMetadataValidator::ENCODING_JSON);

        $this->assertSame(2, $fields['login_id']);
        $this->assertSame(3, $fields['client_id']);
        $this->assertSame('{"theme":"dark"}', $row->metadata);
        $this->assertSame(ClientMetadataValidator::ENCODING_JSON, $row->encoding);
        $this->assertInstanceOf(\DateTimeImmutable::class, $row->updated_at);
    }

    public function testUpsertMetadataCreatesRowWhenMissing(): void
    {
        $fields = [];
        $persisted = null;
        $repository = $this->getMockBuilder(UserLoginClientRepository::class)
            ->onlyMethods(['clear', 'find', 'persist', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('find')->willReturn(0);
        $repository->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (UserLoginClient $row) use (&$persisted): UserLoginClient {
                $persisted = $row;
                return $row;
            });
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $repository->upsertMetadata(1, 2, 3, '{"theme":"dark"}', ClientMetadataValidator::ENCODING_JSON);

        $this->assertSame(2, $fields['login_id']);
        $this->assertSame(3, $fields['client_id']);
        $this->assertInstanceOf(UserLoginClient::class, $persisted);
        $this->assertSame(1, $persisted->getUserId());
        $this->assertSame(2, $persisted->getLoginId());
        $this->assertSame(3, $persisted->getClientDbId());
        $this->assertSame('{"theme":"dark"}', $persisted->getMetadata());
        $this->assertSame(ClientMetadataValidator::ENCODING_JSON, $persisted->getEncoding());
    }
}
