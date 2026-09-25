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
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAuthCode;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AuthCode;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\AuthCodeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use PHPUnit\Framework\TestCase;

class AuthCodeRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'auth_codes';
        $this->fields = [
            'token_id' => FieldDefinition::builder()->name('token_id')->type(FieldType::STRING)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'user_identifier' => FieldDefinition::builder()->name('user_identifier')->type(FieldType::STRING)->build(),
            'redirect_uri' => FieldDefinition::builder()->name('redirect_uri')->type(FieldType::STRING)->build(),
            'expiry_date_time' => FieldDefinition::builder()->name('expiry_date_time')->type(FieldType::DATETIME)->build(),
            'nonce' => FieldDefinition::builder()->name('nonce')->type(FieldType::STRING)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class AuthCodeRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        $_ENV['OAUTH_AUTH_TOKEN_TTL'] = 'PT10M';
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new AuthCodeRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testAuthCodeNonceAccessorRoundTrip(): void
    {
        $this->assertTrue((new \ReflectionMethod(AuthCode::class, 'getNonce'))->isPublic());
        $this->assertTrue((new \ReflectionMethod(AuthCode::class, 'setNonce'))->isPublic());

        $authCode = AuthCode::builder()->build();
        $this->assertNull($authCode->getNonce());

        $authCode->setNonce('stored');
        $this->assertSame('stored', $authCode->getNonce());

        $authCode->setNonce(null);
        $this->assertNull($authCode->getNonce());
    }

    public function testGetNewAuthCodeBuildsOAuthAuthCodeWithRepositoryEntity(): void
    {
        $authCode = (new AuthCodeRepository())->getNewAuthCode();

        $this->assertInstanceOf(OAuthAuthCode::class, $authCode);
        $this->assertNotEmpty($authCode->getIdentifier());
        $this->assertGreaterThan(new \DateTimeImmutable('now'), $authCode->getExpiryDateTime());
        $this->assertSame($authCode->getIdentifier(), $authCode->getAuthCodeEntity()->getIdentifier());
    }

    public function testPersistNewAuthCodeCopiesOAuthFieldsToRepositoryEntity(): void
    {
        $client = Client::builder()->identifier('app')->build();
        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->expects($this->once())->method('fetchBy')->with('identifier', 'app')->willReturn($client);
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->once())->method('getRepository')->with(ClientRepository::class)->willReturn($clientRepository);
        EntityManager::configure($manager, true);

        $authCodeEntity = new class extends AuthCode {
            public ?Client $capturedClient = null;
            public ?string $capturedIdentifier = null;
            public ?\DateTimeInterface $capturedExpiry = null;
            public ?string $capturedUserIdentifier = null;
            public ?string $capturedRedirectUri = null;
            public ?string $capturedNonce = 'unset';
            public int $persistCalls = 0;

            public function setClient(Client $client): void { $this->capturedClient = $client; }
            public function setIdentifier(string $identifier): void { $this->capturedIdentifier = $identifier; }
            public function setExpiryDateTime(\DateTimeInterface $expiry): void { $this->capturedExpiry = $expiry; }
            public function setUserIdentifier(string $userIdentifier): void { $this->capturedUserIdentifier = $userIdentifier; }
            public function setRedirectUri(string $redirectUri): void { $this->capturedRedirectUri = $redirectUri; }
            public function setNonce(?string $nonce): void { $this->capturedNonce = $nonce; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { $this->persistCalls++; return $this; }
        };
        $expiry = new \DateTimeImmutable('+10 minutes');
        $oauthClient = OAuthClient::builder()->identifier('app')->clientEntity($client)->build();
        $oauthAuthCode = OAuthAuthCode::builder()
            ->authCodeEntity($authCodeEntity)
            ->client($oauthClient)
            ->identifier('auth-code-id')
            ->expiryDateTime($expiry)
            ->userIdentifier('uuid-user')
            ->redirectUri('https://client.example/callback')
            ->build();

        (new AuthCodeRepository())->persistNewAuthCode($oauthAuthCode);

        $this->assertSame($client, $authCodeEntity->capturedClient);
        $this->assertSame('auth-code-id', $authCodeEntity->capturedIdentifier);
        $this->assertSame($expiry, $authCodeEntity->capturedExpiry);
        $this->assertSame('uuid-user', $authCodeEntity->capturedUserIdentifier);
        $this->assertSame('https://client.example/callback', $authCodeEntity->capturedRedirectUri);
        $this->assertNull($authCodeEntity->capturedNonce);
        $this->assertSame(1, $authCodeEntity->persistCalls);
        $this->assertArrayNotHasKey('nonce', $_SESSION);
    }

    public function testPersistNewAuthCodeCopiesSessionNonceAndClearsIt(): void
    {
        $_SESSION['nonce'] = 'session-nonce';
        $client = Client::builder()->identifier('app')->build();
        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('fetchBy')->with('identifier', 'app')->willReturn($client);
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getRepository')->with(ClientRepository::class)->willReturn($clientRepository);
        EntityManager::configure($manager, true);

        $authCodeEntity = new class extends AuthCode {
            public ?string $capturedNonce = 'unset';
            public function setClient(Client $client): void {}
            public function setIdentifier(string $identifier): void {}
            public function setExpiryDateTime(\DateTimeInterface $expiry): void {}
            public function setUserIdentifier(string $userIdentifier): void {}
            public function setRedirectUri(string $redirectUri): void {}
            public function setNonce(?string $nonce): void { $this->capturedNonce = $nonce; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { return $this; }
        };
        $oauthClient = OAuthClient::builder()->identifier('app')->clientEntity($client)->build();
        $oauthAuthCode = OAuthAuthCode::builder()
            ->authCodeEntity($authCodeEntity)
            ->client($oauthClient)
            ->identifier('auth-code-id')
            ->expiryDateTime(new \DateTimeImmutable('+10 minutes'))
            ->userIdentifier('uuid-user')
            ->redirectUri('https://client.example/callback')
            ->build();

        (new AuthCodeRepository())->persistNewAuthCode($oauthAuthCode);

        $this->assertSame('session-nonce', $authCodeEntity->capturedNonce);
        $this->assertArrayNotHasKey('nonce', $_SESSION);
    }

    public function testFindNonceByAuthCodeIdReturnsPersistedNonce(): void
    {
        $authCode = new class extends AuthCode {
            public function getNonce(): ?string { return 'stored-nonce'; }
        };
        $repository = $this->getMockBuilder(AuthCodeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'code-id')->willReturn($authCode);

        $this->assertSame('stored-nonce', $repository->findNonceByAuthCodeId('code-id'));
    }

    public function testFindNonceByAuthCodeIdReturnsNullWhenMissingEmptyOrWrongType(): void
    {
        $empty = new class extends AuthCode {
            public function getNonce(): ?string { return ''; }
        };
        $nullNonce = new class extends AuthCode {
            public function getNonce(): ?string { return null; }
        };
        $repository = $this->getMockBuilder(AuthCodeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturnOnConsecutiveCalls(
            null,
            $empty,
            $nullNonce,
            Client::builder()->identifier('other')->build()
        );

        $this->assertNull($repository->findNonceByAuthCodeId('missing'));
        $this->assertNull($repository->findNonceByAuthCodeId('empty'));
        $this->assertNull($repository->findNonceByAuthCodeId('null-nonce'));
        $this->assertNull($repository->findNonceByAuthCodeId('wrong-type'));
    }

    public function testRevokeAuthCodeExpiresFetchedCode(): void
    {
        $authCode = new class extends AuthCode {
            public ?\DateTimeInterface $expiry = null;
            public int $persistCalls = 0;

            public function setExpiryDateTime(\DateTimeInterface $expiry): void { $this->expiry = $expiry; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { $this->persistCalls++; return $this; }
        };

        $repository = $this->getMockBuilder(AuthCodeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'code-id')->willReturn($authCode);

        $repository->revokeAuthCode('code-id');

        $this->assertLessThanOrEqual(new \DateTimeImmutable('now'), $authCode->expiry);
        $this->assertSame(1, $authCode->persistCalls);
    }

    public function testIsAuthCodeRevokedChecksExpiry(): void
    {
        $expired = new class extends AuthCode {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('-1 minute'); }
        };
        $active = new class extends AuthCode {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('+1 minute'); }
        };
        $repository = $this->getMockBuilder(AuthCodeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturnOnConsecutiveCalls($expired, $active);

        $this->assertTrue($repository->isAuthCodeRevoked('expired-code'));
        $this->assertFalse($repository->isAuthCodeRevoked('active-code'));
    }

    public function testDeleteExpiredAuthCodesExecutesDeleteQuery(): void
    {
        $repository = $this->getMockBuilder(AuthCodeRepository::class)
            ->onlyMethods(['query', 'execute'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())
            ->method('query')
            ->with('DELETE FROM auth_codes WHERE expiry_date_time < NOW()');
        $repository->expects($this->once())->method('execute');

        $repository->deleteExpiredAuthCodes();
    }
}
