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
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthScope;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Scope;
use Amtgard\IdP\Persistence\Server\Repositories\ScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use PHPUnit\Framework\TestCase;

class ScopeRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'scopes';
        $this->fields = [
            'scope_id' => FieldDefinition::builder()->name('scope_id')->type(FieldType::STRING)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}

class ScopeRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new ScopeRepositoryTestTableSchema());
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testGetScopeEntityByIdentifierReturnsOAuthScopeWhenFound(): void
    {
        $scope = Scope::builder()->identifier('email')->build();
        $repository = $this->getMockBuilder(ScopeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'email')->willReturn($scope);

        $oauthScope = $repository->getScopeEntityByIdentifier('email');

        $this->assertInstanceOf(OAuthScope::class, $oauthScope);
        $this->assertSame('email', $oauthScope->getIdentifier());
        $this->assertSame($scope, $oauthScope->getScopeEntity());
    }

    public function testGetScopeEntityByIdentifierReturnsNullWhenMissing(): void
    {
        $repository = $this->getMockBuilder(ScopeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturn(null);

        $this->assertNull($repository->getScopeEntityByIdentifier('unknown'));
    }

    public function testFinalizeScopesKeepsOnlyValidScopes(): void
    {
        $email = OAuthScope::builder()->identifier('email')->build();
        $profile = OAuthScope::builder()->identifier('profile')->build();
        $admin = OAuthScope::builder()->identifier('admin')->build();

        $result = (new ScopeRepository())->finalizeScopes(
            [$email, $profile, $admin],
            'authorization_code',
            $this->createMock(ClientEntityInterface::class)
        );

        $this->assertSame([$email, $profile], $result);
    }

    public function testTableAndEntityClassMetadata(): void
    {
        $this->assertSame('scopes', ScopeRepository::getTableName());
        $this->assertSame(Scope::class, ScopeRepository::getEntityClass());
    }
}
