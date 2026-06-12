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
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthAccessToken;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository;
use PHPUnit\Framework\TestCase;

class AccessTokenRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'access_tokens';
        $this->fields = [
            'token_id' => FieldDefinition::builder()->name('token_id')->type(FieldType::STRING)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'user_identifier' => FieldDefinition::builder()->name('user_identifier')->type(FieldType::STRING)->build(),
            'expiry_date_time' => FieldDefinition::builder()->name('expiry_date_time')->type(FieldType::DATETIME)->build(),
            'client_secret' => FieldDefinition::builder()->name('client_secret')->type(FieldType::STRING)->build(),
            'name' => FieldDefinition::builder()->name('name')->type(FieldType::STRING)->build(),
            'redirect_uri' => FieldDefinition::builder()->name('redirect_uri')->type(FieldType::STRING)->build(),
            'is_confidential' => FieldDefinition::builder()->name('is_confidential')->type(FieldType::BOOL)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class AccessTokenRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['OAUTH_ACCESS_TOKEN_TTL'] = 'PT1H';
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new AccessTokenRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testGetNewTokenBuildsOAuthTokenWithRepositoryEntity(): void
    {
        $clientEntity = Client::builder()->identifier('client-id')->build();
        $client = new class($clientEntity) extends OAuthClient {
            public function __construct(private Client $testClientEntity) {}
            public function getClientEntity(): Client { return $this->testClientEntity; }
        };

        $repository = new AccessTokenRepository();
        $token = $repository->getNewToken($client, [], 'user-uuid');

        $this->assertInstanceOf(OAuthAccessToken::class, $token);
        $this->assertNotEmpty($token->getIdentifier());
        $this->assertSame('user-uuid', $token->getUserIdentifier());
        $this->assertSame($client, $token->getClient());
        $this->assertGreaterThan(new \DateTimeImmutable('now'), $token->getExpiryDateTime());
        $this->assertSame($token->getIdentifier(), $token->getAccessTokenEntity()->getIdentifier());
        $this->assertSame($clientEntity, $token->getAccessTokenEntity()->getClient());
    }

    public function testPersistNewAccessTokenCopiesOAuthFieldsToRepositoryEntity(): void
    {
        $clientEntity = Client::builder()->identifier('client-id')->build();
        $client = new class($clientEntity) extends OAuthClient {
            public function __construct(private Client $testClientEntity) {}
            public function getClientEntity(): Client { return $this->testClientEntity; }
        };
        $accessToken = new class extends AccessToken {
            public ?Client $capturedClient = null;
            public ?string $capturedIdentifier = null;
            public ?\DateTimeInterface $capturedExpiry = null;
            public ?string $capturedUserIdentifier = null;
            public int $persistCalls = 0;

            public function setClient(Client $client): void { $this->capturedClient = $client; }
            public function setIdentifier(string $identifier): void { $this->capturedIdentifier = $identifier; }
            public function setExpiryDateTime(\DateTimeInterface $expiry): void { $this->capturedExpiry = $expiry; }
            public function setUserIdentifier(?string $userIdentifier): void { $this->capturedUserIdentifier = $userIdentifier; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { $this->persistCalls++; return $this; }
        };
        $expiry = new \DateTimeImmutable('+1 hour');
        $oauthAccessToken = OAuthAccessToken::builder()
            ->accessTokenEntity($accessToken)
            ->client($client)
            ->identifier('token-id')
            ->expiryDateTime($expiry)
            ->userIdentifier('user-uuid')
            ->build();

        (new AccessTokenRepository())->persistNewAccessToken($oauthAccessToken);

        $this->assertSame($clientEntity, $accessToken->capturedClient);
        $this->assertSame('token-id', $accessToken->capturedIdentifier);
        $this->assertSame($expiry, $accessToken->capturedExpiry);
        $this->assertSame('user-uuid', $accessToken->capturedUserIdentifier);
        $this->assertSame(1, $accessToken->persistCalls);
    }

    public function testRevokeAccessTokenExpiresFetchedToken(): void
    {
        $accessToken = new class extends AccessToken {
            public ?\DateTimeInterface $expiry = null;
            public int $persistCalls = 0;

            public function setExpiryDateTime(\DateTimeInterface $expiry): void { $this->expiry = $expiry; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { $this->persistCalls++; return $this; }
        };

        $repository = $this->getMockBuilder(AccessTokenRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'token-id')->willReturn($accessToken);

        $repository->revokeAccessToken('token-id');

        $this->assertLessThanOrEqual(new \DateTimeImmutable('now'), $accessToken->expiry);
        $this->assertSame(1, $accessToken->persistCalls);
    }

    public function testIsAccessTokenRevokedChecksExpiry(): void
    {
        $expired = new class extends AccessToken {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('-1 minute'); }
        };
        $active = new class extends AccessToken {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('+1 minute'); }
        };

        $repository = $this->getMockBuilder(AccessTokenRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturnOnConsecutiveCalls($expired, $active);

        $this->assertTrue($repository->isAccessTokenRevoked('expired-token'));
        $this->assertFalse($repository->isAccessTokenRevoked('active-token'));
    }

    public function testDeleteExpiredTokensExecutesDeleteQuery(): void
    {
        $repository = $this->getMockBuilder(AccessTokenRepository::class)
            ->onlyMethods(['query', 'execute'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())
            ->method('query')
            ->with('DELETE FROM access_tokens WHERE expiry_date_time < NOW()');
        $repository->expects($this->once())->method('execute');

        $repository->deleteExpiredTokens();
    }
}
