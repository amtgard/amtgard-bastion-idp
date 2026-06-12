<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthClient;
use PHPUnit\Framework\TestCase;

class ClientRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $database = $this->createMock(Database::class);
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $repositoryPolicy = $this->createMock(RepositoryPolicy::class);
        $tableSchema = new class extends TableSchema {
            public function __construct()
            {
                $this->tableName = 'clients';
                $this->fields = [
                    'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::STRING)->build(),
                    'client_secret' => FieldDefinition::builder()->name('client_secret')->type(FieldType::STRING)->build(),
                    'name' => FieldDefinition::builder()->name('name')->type(FieldType::STRING)->build(),
                    'redirect_uri' => FieldDefinition::builder()->name('redirect_uri')->type(FieldType::STRING)->build(),
                    'is_confidential' => FieldDefinition::builder()->name('is_confidential')->type(FieldType::BOOL)->build(),
                ];
                $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
            }
        };
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn($tableSchema);
        EntityManager::configure(
            EntityManager::builder()
                ->database($database)
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($repositoryPolicy)
                ->build(),
            true
        );
    }

    public function testValidateClientReturnsFalseWhenClientMissing(): void
    {
        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['getClientEntity'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('getClientEntity')->willReturn(null);

        $this->assertFalse($repository->validateClient('missing', 'secret', 'confidential_basic'));
    }

    public function testValidateClientChecksSecret(): void
    {
        $clientEntity = Client::builder()
            ->identifier('app')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->build();

        $oauthClient = OAuthClient::builder()
            ->clientEntity($clientEntity)
            ->identifier('app')
            ->isConfidential(true)
            ->name('App')
            ->redirectUri(['http://localhost/cb'])
            ->build();

        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['getClientEntity'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('getClientEntity')->willReturn($oauthClient);

        $this->assertTrue($repository->validateClient('app', 'secret', 'confidential_basic'));
        $this->assertFalse($repository->validateClient('app', 'wrong', 'confidential_basic'));
    }

    public function testGetClientEntityWrapsJsonRedirectUriArray(): void
    {
        $client = Client::builder()
            ->identifier('app')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('["http://localhost/cb","http://localhost/other"]')
            ->isConfidential(true)
            ->build();

        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturn($client);

        $entity = $repository->getClientEntity('app');

        $this->assertNotNull($entity);
        $this->assertSame(['http://localhost/cb', 'http://localhost/other'], $entity->getRedirectUri());
    }

    public function testGetAllClientsReturnsCurrentRows(): void
    {
        $clientA = Client::builder()->identifier('a')->build();
        $clientB = Client::builder()->identifier('b')->build();
        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['clear', 'find', 'next', 'getCurrent'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('find');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, false);
        $repository->method('getCurrent')->willReturnOnConsecutiveCalls($clientA, $clientB);

        $this->assertSame([$clientA, $clientB], $repository->getAllClients());
    }

    public function testFindClientByIdentifierFetchesByIdentifier(): void
    {
        $client = Client::builder()->identifier('app')->build();
        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('fetchBy')->with('identifier', 'app')->willReturn($client);

        $this->assertSame($client, $repository->findClientByIdentifier('app'));
    }

    public function testFindActiveClientsForUserReturnsProjectedRows(): void
    {
        $clientA = new class extends Client {
            public string $client_id = 'app-a';
            public string $name = 'App A';
        };
        $clientB = new class extends Client {
            public string $client_id = 'app-b';
            public string $name = 'App B';
        };
        $fields = [];
        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['clear', 'query', 'execute', 'next', 'getEntity', '__set'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->expects($this->once())->method('clear');
        $repository->expects($this->once())->method('query')->with($this->stringContains('FROM user_client_authorizations'));
        $repository->expects($this->once())->method('execute');
        $repository->method('next')->willReturnOnConsecutiveCalls(true, true, false);
        $repository->method('getEntity')->willReturnOnConsecutiveCalls($clientA, $clientB);
        $repository->method('__set')->willReturnCallback(function (string $name, $value) use (&$fields): void {
            $fields[$name] = $value;
        });

        $this->assertSame([
            ['client_id' => 'app-a', 'client_name' => 'App A'],
            ['client_id' => 'app-b', 'client_name' => 'App B'],
        ], $repository->findActiveClientsForUser(10));
        $this->assertSame(10, $fields['user_id']);
    }

    public function testGetClientEntityWrapsSingleRedirectUriAndMissingClient(): void
    {
        $client = Client::builder()
            ->identifier('app')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(false)
            ->build();

        $repository = $this->getMockBuilder(ClientRepository::class)
            ->onlyMethods(['fetchBy'])
            ->disableOriginalConstructor()
            ->getMock();
        $repository->method('fetchBy')->willReturnOnConsecutiveCalls($client, null);

        $entity = $repository->getClientEntity('app');

        $this->assertNotNull($entity);
        $this->assertSame(['http://localhost/cb'], $entity->getRedirectUri());
        $this->assertFalse($entity->isConfidential());
        $this->assertNull($repository->getClientEntity('missing'));
    }
}
