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
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthRefreshToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\AccessToken;
use Amtgard\IdP\Persistence\Server\Entities\Repository\RefreshToken;
use Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository;
use PHPUnit\Framework\TestCase;

class RefreshTokenRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'refresh_tokens';
        $this->fields = [
            'token_id' => FieldDefinition::builder()->name('token_id')->type(FieldType::STRING)->build(),
            'access_token_id' => FieldDefinition::builder()->name('access_token_id')->type(FieldType::INTEGER)->build(),
            'expiry_date_time' => FieldDefinition::builder()->name('expiry_date_time')->type(FieldType::DATETIME)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class RefreshTokenRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['OAUTH_REFRESH_TOKEN_TTL'] = 'P1D';
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new RefreshTokenRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testGetNewRefreshTokenBuildsOAuthTokenWithRepositoryEntity(): void
    {
        $token = (new RefreshTokenRepository())->getNewRefreshToken();

        $this->assertInstanceOf(OAuthRefreshToken::class, $token);
        $this->assertNotEmpty($token->getIdentifier());
        $this->assertGreaterThan(new \DateTimeImmutable('now'), $token->getExpiryDateTime());
        $this->assertSame($token->getIdentifier(), $token->getRefreshToken()->getIdentifier());
    }

    public function testPersistNewRefreshTokenStoresOAuthAccessTokenEntity(): void
    {
        $accessTokenEntity = new AccessToken();
        $accessToken = OAuthAccessToken::builder()
            ->accessTokenEntity($accessTokenEntity)
            ->identifier('access-token-id')
            ->build();
        $refreshToken = $this->refreshTokenStub();
        $expiry = new \DateTimeImmutable('+1 day');
        $oauthRefreshToken = OAuthRefreshToken::builder()
            ->refreshToken($refreshToken)
            ->identifier('refresh-token-id')
            ->expiryDateTime($expiry)
            ->accessToken($accessToken)
            ->build();

        (new RefreshTokenRepository())->persistNewRefreshToken($oauthRefreshToken);

        $this->assertSame('refresh-token-id', $refreshToken->capturedIdentifier);
        $this->assertSame($expiry, $refreshToken->capturedExpiry);
        $this->assertSame($accessTokenEntity, $refreshToken->capturedAccessToken);
        $this->assertSame(1, $refreshToken->persistCalls);
    }

    public function testPersistNewRefreshTokenAcceptsRepositoryAccessTokenEntity(): void
    {
        $accessTokenEntity = new AccessToken();
        $refreshToken = $this->refreshTokenStub();
        $oauthRefreshToken = OAuthRefreshToken::builder()
            ->refreshToken($refreshToken)
            ->identifier('refresh-token-id')
            ->expiryDateTime(new \DateTimeImmutable('+1 day'))
            ->accessToken($accessTokenEntity)
            ->build();

        (new RefreshTokenRepository())->persistNewRefreshToken($oauthRefreshToken);

        $this->assertSame($accessTokenEntity, $refreshToken->capturedAccessToken);
    }

    public function testRevokeRefreshTokenExpiresFetchedToken(): void
    {
        $refreshToken = $this->refreshTokenStub();
        $repository = $this->getMockBuilder(RefreshTokenRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'token-id')->willReturn($refreshToken);

        $repository->revokeRefreshToken('token-id');

        $this->assertLessThanOrEqual(new \DateTimeImmutable('now'), $refreshToken->capturedExpiry);
        $this->assertSame(1, $refreshToken->persistCalls);
    }

    public function testIsRefreshTokenRevokedChecksExpiry(): void
    {
        $expired = new class extends RefreshToken {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('-1 minute'); }
        };
        $active = new class extends RefreshToken {
            public function getExpiryDateTime(): \DateTimeInterface { return new \DateTimeImmutable('+1 minute'); }
        };
        $repository = $this->getMockBuilder(RefreshTokenRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturnOnConsecutiveCalls($expired, $active);

        $this->assertTrue($repository->isRefreshTokenRevoked('expired-token'));
        $this->assertFalse($repository->isRefreshTokenRevoked('active-token'));
    }

    public function testDeleteExpiredAndOrphanedTokensExecuteQueries(): void
    {
        $queries = [];
        $repository = $this->getMockBuilder(RefreshTokenRepository::class)
            ->onlyMethods(['query', 'execute'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->exactly(2))->method('query')->willReturnCallback(function (string $query) use (&$queries): void {
            $queries[] = $query;
        });
        $repository->expects($this->exactly(2))->method('execute');

        $repository->deleteExpiredTokens();
        $repository->deleteOrphanedRefreshTokens();

        $this->assertSame([
            'DELETE FROM refresh_tokens WHERE expiry_date_time < NOW()',
            'DELETE refresh_tokens FROM refresh_tokens LEFT JOIN access_tokens ON refresh_tokens.access_token_id = access_tokens.id WHERE access_tokens.id IS NULL',
        ], $queries);
    }

    private function refreshTokenStub(): RefreshToken
    {
        return new class extends RefreshToken {
            public ?string $capturedIdentifier = null;
            public ?\DateTimeInterface $capturedExpiry = null;
            public ?AccessToken $capturedAccessToken = null;
            public int $persistCalls = 0;

            public function setIdentifier(string $identifier): void { $this->capturedIdentifier = $identifier; }
            public function setExpiryDateTime(\DateTimeInterface $expiry): void { $this->capturedExpiry = $expiry; }
            public function setAccessToken(AccessToken $accessToken): void { $this->capturedAccessToken = $accessToken; }
            public function getMapper(): object { return new \stdClass(); }
            public function persist($mapper = null): static { $this->persistCalls++; return $this; }
        };
    }
}
