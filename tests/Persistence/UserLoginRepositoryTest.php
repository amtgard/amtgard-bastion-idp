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
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

class UserLoginRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'user_logins';
        $this->fields = [
            'id' => FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build(),
            'user_id' => FieldDefinition::builder()->name('user_id')->type(FieldType::INTEGER)->build(),
            'password' => FieldDefinition::builder()->name('password')->type(FieldType::STRING)->build(),
            'provider_id' => FieldDefinition::builder()->name('provider_id')->type(FieldType::STRING)->build(),
            'type' => FieldDefinition::builder()->name('type')->type(FieldType::STRING)->build(),
            'avatar_url' => FieldDefinition::builder()->name('avatar_url')->type(FieldType::STRING)->build(),
            'created_at' => FieldDefinition::builder()->name('created_at')->type(FieldType::DATETIME)->build(),
            'updated_at' => FieldDefinition::builder()->name('updated_at')->type(FieldType::DATETIME)->build(),
            'refresh_token' => FieldDefinition::builder()->name('refresh_token')->type(FieldType::STRING)->build(),
            'expiry_date_time' => FieldDefinition::builder()->name('expiry_date_time')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class UserLoginRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new UserLoginRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testGetLoginByProviderIdFetchesByProviderId(): void
    {
        $login = new UserLoginEntity();
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('providerId', 'provider-1')->willReturn($login);

        $this->assertSame($login, $repository->getLoginByProviderId('provider-1'));
    }

    public function testFindLoginByIdFetchesById(): void
    {
        $login = new UserLoginEntity();
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['fetch'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetch')->with(42)->willReturn($login);

        $this->assertSame($login, $repository->findLoginById(42));
    }

    public function testLoginBelongsToUserReturnsTrueWhenLoginMatchesUser(): void
    {
        $login = new class extends UserLoginEntity {
            public int $userId = 10;
        };

        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['findLoginById'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findLoginById')->with(42)->willReturn($login);

        $this->assertTrue($repository->loginBelongsToUser(42, 10));
        $this->assertFalse($repository->loginBelongsToUser(42, 99));
    }

    public function testLoginBelongsToUserReturnsFalseWhenLoginMissing(): void
    {
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['findLoginById'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('findLoginById')->willReturn(null);

        $this->assertFalse($repository->loginBelongsToUser(42, 10));
    }

    public function testGetLoginByUserReturnsNullWhenNoLocalLoginExists(): void
    {
        $user = new class {
            public function getId(): int { return 10; }
        };
        $fields = [];
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', 'next', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('query')->with("select * from user_logins where user_id = :user_id and type = 'local'");
        $repository->expects($this->once())->method('execute');
        $repository->expects($this->once())->method('next')->willReturn(false);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertNull($repository->getLoginByUser($user));
        $this->assertSame(10, $fields['user_id']);
    }

    public function testResolveDefaultLoginIdForUserReturnsLocalLoginId(): void
    {
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', 'next', '__set', '__get'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('query')->with("select id from user_logins where user_id = :user_id and type = 'local' limit 1");
        $repository->method('next')->willReturn(true);
        $repository->method('__get')->with('id')->willReturn(42);

        $this->assertSame(42, $repository->resolveDefaultLoginIdForUser(10));
    }

    public function testResolveDefaultLoginIdForUserFallsBackToFirstLogin(): void
    {
        $login = new class extends UserLoginEntity {
            public function getId(): ?int { return 77; }
        };
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', 'next', '__set', 'getAllLoginsForUser'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('next')->willReturn(false);
        $repository->method('getAllLoginsForUser')->with(10)->willReturn([$login]);

        $this->assertSame(77, $repository->resolveDefaultLoginIdForUser(10));
    }

    public function testResolveDefaultLoginIdForUserReturnsNullWhenNoLoginsExist(): void
    {
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', 'next', '__set', 'getAllLoginsForUser'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('next')->willReturn(false);
        $repository->method('getAllLoginsForUser')->willReturn([]);

        $this->assertNull($repository->resolveDefaultLoginIdForUser(10));
    }

    public function testCreateLocalLoginBuildsLocalLoginForUser(): void
    {
        $user = $this->testUser();

        $login = (new UserLoginRepository())->createLocalLogin($user, 'secret');

        $this->assertSame($user, $login->user);
        $this->assertSame('local', $login->getType());
        $this->assertTrue(password_verify('secret', $login->getPassword()));
    }

    public function testCreateLoginFromDiscordDataUsesDefaultAvatar(): void
    {
        $user = $this->testUser();
        $token = new AccessToken(['access_token' => 'access', 'refresh_token' => 'refresh-token']);
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist')->with($this->isInstanceOf(UserLoginEntity::class));

        $login = $repository->createLoginFromDiscordData($user, [
            'id' => 'discord-id',
            'avatar' => null,
        ], $token);

        $this->assertSame($user, $login->user);
        $this->assertSame('discord-id', $login->getProviderId());
        $this->assertSame('https://cdn.discordapp.com/embed/avatars/0.png', $login->getAvatarUrl());
        $this->assertSame('refresh-token', $login->getRefreshToken());
    }

    public function testCreateLoginFromAppleDataStoresProviderAndRefreshToken(): void
    {
        $user = $this->testUser();
        $token = new AccessToken(['access_token' => 'access', 'refresh_token' => 'refresh-token']);
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist')->with($this->isInstanceOf(UserLoginEntity::class));

        $login = $repository->createLoginFromAppleData($user, [
            'sub' => 'apple-sub',
            'email' => 'apple@example.com',
        ], $token);

        $this->assertSame($user, $login->user);
        $this->assertSame('apple-sub', $login->getProviderId());
        $this->assertSame('', $login->getAvatarUrl());
        $this->assertSame('refresh-token', $login->getRefreshToken());
    }

    public function testUpdateLoginTokensPersistsRefreshTokenAndExpiry(): void
    {
        $login = new class extends UserLoginEntity {
            public ?string $refreshToken = null;
            public ?\DateTime $expiryDateTime = null;

            public function setRefreshToken(?string $refreshToken): void
            {
                $this->refreshToken = $refreshToken;
            }

            public function setExpiryDateTime(?\DateTime $expiryDateTime): void
            {
                $this->expiryDateTime = $expiryDateTime;
            }
        };

        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist')->with($login)->willReturn($login);

        $token = new AccessToken([
            'access_token' => 'access',
            'refresh_token' => 'refresh-token',
            'expires' => time() + 3600,
        ]);

        $result = $repository->updateLoginTokens($login, fn ($t) => $t->getRefreshToken(), $token);

        $this->assertSame($login, $result);
        $this->assertSame('refresh-token', $login->refreshToken);
        $this->assertInstanceOf(\DateTime::class, $login->expiryDateTime);
    }

    public function testUpdateLoginTokensSkipsEmptyRefreshAndMissingExpiry(): void
    {
        $login = new class extends UserLoginEntity {
            public ?string $refreshToken = null;
            public ?\DateTime $expiryDateTime = null;

            public function setRefreshToken(?string $refreshToken): void { $this->refreshToken = $refreshToken; }
            public function setExpiryDateTime(?\DateTime $expiryDateTime): void { $this->expiryDateTime = $expiryDateTime; }
        };
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist')->with($login)->willReturn($login);
        $token = new AccessToken(['access_token' => 'access']);

        $repository->updateLoginTokens($login, fn () => null, $token);

        $this->assertNull($login->refreshToken);
        $this->assertNull($login->expiryDateTime);
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('user_logins', UserLoginRepository::getTableName());
        $this->assertSame(UserLoginEntity::class, UserLoginRepository::getEntityClass());
    }

    public function testCreateLoginFromGoogleDataPersistsProviderLogin(): void
    {
        $user = $this->testUser();
        $token = new AccessToken(['access_token' => 'access', 'refresh_token' => 'refresh-token', 'expires' => time() + 3600]);
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist');

        $login = $repository->createLoginFromGoogleData($user, [
            'sub' => 'google-sub',
            'picture' => 'https://example.com/avatar.jpg',
        ], $token);

        $this->assertSame('google-sub', $login->getProviderId());
        $this->assertSame('refresh-token', $login->getRefreshToken());
    }

    public function testCreateLoginFromFacebookDataPersistsProviderLogin(): void
    {
        $user = $this->testUser();
        $token = new AccessToken(['access_token' => 'long-token', 'expires' => time() + 3600]);
        $repository = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['persist'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('persist');

        $login = $repository->createLoginFromFacebookData($user, [
            'id' => 'facebook-id',
            'picture_url' => 'https://facebook.example/avatar.jpg',
        ], $token);

        $this->assertSame('facebook-id', $login->getProviderId());
        $this->assertSame('long-token', $login->getRefreshToken());
    }

    public function testGetAllLoginsForUserReturnsMappedEntities(): void
    {
        $login = new UserLoginEntity();
        $repository = new class([$login]) extends UserLoginRepository {
            public function __construct(private array $logins) {}

            public function getAllLoginsForUser(int $userId): array
            {
                return $this->logins;
            }
        };

        $logins = $repository->getAllLoginsForUser(10);

        $this->assertSame([$login], $logins);
    }

    private function testUser(): UserEntity
    {
        $user = new class extends UserEntity {};
        $id = new \ReflectionProperty(UserEntity::class, 'id');
        $id->setAccessible(true);
        $id->setValue($user, 10);

        return $user;
    }
}
