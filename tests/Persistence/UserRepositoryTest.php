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
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new UserRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testUserExistsClearsRepositoryAndFindsByEmail(): void
    {
        $repository = new class(1) extends UserRepository {
            public array $fields = [];
            public int $clearCalls = 0;

            public function __construct(private int $findCount) {}
            public function __set(string $name, $value): void { $this->fields[$name] = $value; }
            public function clear(): void { $this->clearCalls++; }
            public function find(): int { return $this->findCount; }
        };

        $this->assertTrue($repository->userExists('user@example.com'));
        $this->assertSame(1, $repository->clearCalls);
        $this->assertSame('user@example.com', $repository->fields['email']);
    }

    public function testUserExistsReturnsFalseWhenFindReturnsZero(): void
    {
        $repository = new class(0) extends UserRepository {
            public function __construct(private int $findCount) {}
            public function __set(string $name, $value): void {}
            public function clear(): void {}
            public function find(): int { return $this->findCount; }
        };

        $this->assertFalse($repository->userExists('missing@example.com'));
    }

    public function testGetUserByEmailFetchesByEmailField(): void
    {
        $user = new UserEntity();
        $repository = $this->getMockBuilder(UserRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('email', 'user@example.com')->willReturn($user);

        $this->assertSame($user, $repository->getUserByEmail('user@example.com'));
    }

    public function testFindUserByUserIdFetchesByUserIdField(): void
    {
        $user = new UserEntity();
        $repository = $this->getMockBuilder(UserRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('user_id', 'uuid-1')->willReturn($user);

        $this->assertSame($user, $repository->findUserByUserId('uuid-1'));
    }

    public function testGetUserEntityByIdWrapsUserInOAuthEntity(): void
    {
        $user = new class extends UserEntity {
            public function getUserId(): string { return 'uuid-1'; }
        };
        $repository = $this->getMockBuilder(UserRepository::class)
            ->onlyMethods(['findUserByUserId'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('findUserByUserId')->with('uuid-1')->willReturn($user);

        $oauthUser = $repository->getUserEntityById('uuid-1');

        $this->assertInstanceOf(OAuthUser::class, $oauthUser);
        $this->assertSame('uuid-1', $oauthUser->getIdentifier());
        $this->assertSame($user, $oauthUser->getUserEntity());
    }

    public function testGetUserEntityByIdReturnsNullWhenUserMissing(): void
    {
        $repository = $this->getMockBuilder(UserRepository::class)
            ->onlyMethods(['findUserByUserId'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('findUserByUserId')->with('missing')->willReturn(null);

        $this->assertNull($repository->getUserEntityById('missing'));
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('users', UserRepository::getTableName());
        $this->assertSame(UserEntity::class, UserRepository::getEntityClass());
    }

    public function testCreateLocalUserPersistsUserWithGeneratedId(): void
    {
        $repository = new UserRepository();
        $user = $repository->createLocalUser('new@example.com', 'Ada', 'Lovelace');

        $this->assertSame('new@example.com', $user->getEmail());
        $this->assertSame('Ada', $user->getFirstName());
        $this->assertSame('Lovelace', $user->getLastName());
        $this->assertNotEmpty($user->getUserId());
    }

    public function testCreateUserFromGoogleDataPersistsUser(): void
    {
        $repository = new UserRepository();
        $user = $repository->createUserFromGoogleData([
            'email' => 'google@example.com',
            'given_name' => 'Goog',
            'family_name' => 'User',
        ]);

        $this->assertSame('google@example.com', $user->getEmail());
        $this->assertNotEmpty($user->getUserId());
    }

    public function testCreateUserFromFacebookDataPersistsUser(): void
    {
        $repository = new UserRepository();
        $user = $repository->createUserFromFacebookData([
            'email' => 'fb@example.com',
            'first_name' => 'Face',
            'last_name' => 'Book',
        ]);

        $this->assertSame('fb@example.com', $user->getEmail());
        $this->assertNotEmpty($user->getUserId());
    }
}

class UserRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'users';
        $this->fields = [
            'email' => FieldDefinition::builder()->name('email')->type(FieldType::STRING)->build(),
            'first_name' => FieldDefinition::builder()->name('first_name')->type(FieldType::STRING)->build(),
            'last_name' => FieldDefinition::builder()->name('last_name')->type(FieldType::STRING)->build(),
            'user_id' => FieldDefinition::builder()->name('user_id')->type(FieldType::STRING)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}
