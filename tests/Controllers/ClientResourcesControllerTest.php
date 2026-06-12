<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;
use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\IdP\Controllers\Resource\ClientResourcesController;
use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class ClientResourcesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $database = $this->createMock(Database::class);
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $repositoryPolicy = $this->createMock(RepositoryPolicy::class);
        $tableSchema = new ClientTestTableSchema();
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

    public function testUpsertUserMetadataReturns204(): void
    {
        $user = new class extends \Amtgard\IdP\Persistence\Client\Entities\UserEntity {
            public function getId(): int
            {
                return 10;
            }

            public function getUserId(): string
            {
                return 'uuid-123';
            }
        };

        $client = new class extends Client {
            public function getId(): int
            {
                return 5;
            }
        };

        $userRepository = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class);
        $userRepository->method('findUserByUserId')->with('uuid-123')->willReturn($user);

        $userLoginRepository = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class);
        $userLoginRepository->method('loginBelongsToUser')->with(42, 10)->willReturn(true);

        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->expects($this->once())
            ->method('upsertMetadata')
            ->with(
                10,
                42,
                5,
                $this->callback(function (string $payload): bool {
                    $decoded = json_decode($payload, true);
                    return is_array($decoded) && ($decoded['role'] ?? null) === 'member';
                }),
                \Amtgard\IdP\Utility\ClientMetadataValidator::ENCODING_JSON
            );

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->once())->method('invalidateUser')->with('uuid-123');

        $controller = $this->makeController(
            new ClientResourcesRequestResolver($userRepository, $userLoginRepository),
            $metadataRepository,
            $redis
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-123',
            'login_id' => 42,
            'metadata' => ['role' => 'member'],
        ]);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);

        $response = $this->mockResponse();

        $result = $controller->upsertUserMetadata($request, $response);
        $this->assertSame($response, $result);
    }

    public function testGetServiceFormatReturnsEffectiveDefaultWhenUnset(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $payload = json_decode($json, true);
                return ($payload['iam_service'] ?? null) === 'Skbc'
                    && ($payload['is_default'] ?? null) === true
                    && ($payload['service_format'] ?? null) === ['Configuration', 'Game', 'Kingdom', 'Park'];
            }));

        $controller->getServiceFormat($request, $response);
    }

    public function testCreateServiceFormatReturns409WhenAlreadyConfigured(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->iamServiceFormat('["Configuration","Kingdom"]')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'service_format' => ['Configuration', 'Kingdom', 'Park'],
        ]);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('already configured'));
        $response->expects($this->once())->method('withStatus')->with(409)->willReturnSelf();

        $controller->createServiceFormat($request, $response);
    }

    public function testReplaceServiceFormatPersistsEncodedFormat(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'service_format' => ['Configuration', 'Kingdom', 'EventInstance'],
        ]);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->replaceServiceFormat($request, $response);

        $this->assertSame(
            '["Configuration","Kingdom","EventInstance"]',
            $client->getIamServiceFormat()
        );
    }

    public function testGetServiceFormatReturnsStoredCustomFormat(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->iamServiceFormat('["tenant-id","Kingdom","event-series"]')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $payload = json_decode($json, true);
                return ($payload['iam_service'] ?? null) === 'Skbc'
                    && ($payload['is_default'] ?? null) === false
                    && ($payload['service_format'] ?? null) === ['tenant-id', 'Kingdom', 'event-series'];
            }));

        $controller->getServiceFormat($request, $response);
    }

    public function testCreateServiceFormatPersistsEncodedFormat(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'service_format' => ['tenant-id', 'Kingdom'],
        ]);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->createServiceFormat($request, $response);

        $this->assertSame('["tenant-id","Kingdom"]', $client->getIamServiceFormat());
    }

    public function testCreateServiceFormatReturns400WhenServiceFormatMissing(): void
    {
        $client = $this->serviceFormatClient();
        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([]);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('service_format array is required'));
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $controller->createServiceFormat($request, $response);
    }

    public function testCreateServiceFormatReturns400WhenServiceFormatEmpty(): void
    {
        $client = $this->serviceFormatClient();
        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn(['service_format' => []]);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('iam_service_format must be a JSON array'));
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $controller->createServiceFormat($request, $response);
    }

    public function testReplaceServiceFormatReturns400WhenSlotNameEmpty(): void
    {
        $client = $this->serviceFormatClient();
        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'service_format' => ['Configuration', ''],
        ]);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->stringContains('iam_service_format entries must be non-empty strings'));
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $controller->replaceServiceFormat($request, $response);
    }

    public function testReplaceServiceFormatPersistsCustomSlotNames(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();

        $controller = $this->makeController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'service_format' => ['tenant-id', 'Kingdom', 'event-series'],
        ]);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->replaceServiceFormat($request, $response);

        $this->assertSame(
            '["tenant-id","Kingdom","event-series"]',
            $client->getIamServiceFormat()
        );
    }

    public function testAddPolicyClaimReturns204AndInvalidatesCache(): void
    {
        [$user, $client, $resolver, $policyRepo, $redis] = $this->policyContext();
        $policyRepo->expects($this->once())->method('addClaim')->with(
            10,
            'Skbc',
            ':0::::',
            'Officer/Approve',
            10,
            5
        );
        $redis->expects($this->once())->method('invalidateUser')->with('uuid-123');

        $controller = $this->makeController($resolver, null, $redis, $policyRepo);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-123',
            'provisos' => ':0::::',
            'resource' => 'Officer/Approve',
        ]);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->addPolicyClaim($request, $response);
    }

    public function testAddPolicyClaimReturns400WhenRepositoryRejectsClaim(): void
    {
        [$user, $client, $resolver, $policyRepo] = $this->policyContext();
        $policyRepo->method('addClaim')->willThrowException(new \InvalidArgumentException('Invalid ORN claim'));

        $controller = $this->makeController($resolver, null, null, $policyRepo);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->with(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE)
            ->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-123',
            'provisos' => 'bad',
            'resource' => 'Officer/Approve',
        ]);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())->method('write')->with($this->stringContains('Invalid ORN claim'));
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $controller->addPolicyClaim($request, $response);
    }

    public function testDeletePolicyClaimReturns204(): void
    {
        [$user, $client, $resolver, $policyRepo, $redis] = $this->policyContext();
        $policyRepo->expects($this->once())->method('deleteClaim');
        $redis->expects($this->once())->method('invalidateUser')->with('uuid-123');

        $controller = $this->makeController($resolver, null, $redis, $policyRepo);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);
        $request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-123',
            'provisos' => ':0::::',
            'resource' => 'Officer/Approve',
        ]);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->deletePolicyClaim($request, $response);
    }

    public function testListPolicyClaimsReturnsClaims(): void
    {
        [$user, $client, $resolver, $policyRepo] = $this->policyContext();
        $policyRepo->method('listClaimsForUser')->willReturn([
            ['service' => 'Skbc', 'provisos' => ':0::::', 'resource' => 'Officer/Approve'],
        ]);

        $controller = $this->makeController($resolver, null, null, $policyRepo);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $payload = json_decode($json, true);
                return count($payload['claims'] ?? []) === 1;
            }));

        $controller->listPolicyClaims($request, $response, 'uuid-123');
    }

    public function testGetUserMetadataReturnsStoredRow(): void
    {
        [$user, $client, $resolver] = $this->policyContext();
        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->method('getMetadata')->with(42, 5)->willReturn([
            'metadata' => ['role' => 'editor'],
            'encoding' => 'json',
        ]);

        $controller = $this->makeController($resolver, $metadataRepository);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);
        $request->method('getQueryParams')->willReturn(['login_id' => '42']);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $payload = json_decode($json, true);
                return ($payload['login_id'] ?? null) === 42
                    && ($payload['metadata']['role'] ?? null) === 'editor';
            }));

        $controller->getUserMetadata($request, $response, 'uuid-123');
    }

    public function testGetUserMetadataReturns404WhenMissing(): void
    {
        [$user, $client, $resolver] = $this->policyContext();
        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->method('getMetadata')->willReturn(null);

        $controller = $this->makeController($resolver, $metadataRepository);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);
        $request->method('getQueryParams')->willReturn(['login_id' => '42']);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())->method('write')->with($this->stringContains('metadata not found'));
        $response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();

        $controller->getUserMetadata($request, $response, 'uuid-123');
    }

    public function testDeleteUserMetadataReturns204(): void
    {
        [$user, $client, $resolver] = $this->policyContext();
        $metadataRepository = $this->createMock(UserLoginClientRepository::class);
        $metadataRepository->expects($this->once())->method('deleteMetadata')->with(42, 5);
        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->once())->method('invalidateUser')->with('uuid-123');

        $controller = $this->makeController($resolver, $metadataRepository, $redis);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);
        $request->method('getQueryParams')->willReturn(['login_id' => '42']);

        $response = $this->mockResponse();
        $response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $controller->deleteUserMetadata($request, $response, 'uuid-123');
    }

    public function testAddPolicyClaimReturns404ForUnknownUser(): void
    {
        $client = Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();

        $userRepository = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class);
        $userRepository->method('findUserByUserId')->willReturn(null);
        $resolver = new ClientResourcesRequestResolver(
            $userRepository,
            $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class),
        );

        $controller = $this->makeController($resolver);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($client);
        $request->method('getParsedBody')->willReturn(['idp_user_id' => 'missing']);

        [$response, $stream] = $this->mockJsonResponse();
        $stream->expects($this->once())->method('write')->with($this->stringContains('unknown idp_user_id'));
        $response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();

        $controller->addPolicyClaim($request, $response);
    }

    /**
     * @return array{0: object, 1: Client, 2: ClientResourcesRequestResolver, 3: UserPolicyClaimRepository, 4?: RedisCacheRepository}
     */
    private function policyContext(): array
    {
        $user = new class extends \Amtgard\IdP\Persistence\Client\Entities\UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-123'; }
        };

        $client = new class extends Client {
            public function getId(): int { return 5; }
            public function getIamService(): ?string { return 'Skbc'; }
        };

        $userRepository = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class);
        $userRepository->method('findUserByUserId')->with('uuid-123')->willReturn($user);

        $userLoginRepository = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class);
        $userLoginRepository->method('loginBelongsToUser')->with(42, 10)->willReturn(true);

        $resolver = new ClientResourcesRequestResolver($userRepository, $userLoginRepository);
        $policyRepo = $this->createMock(UserPolicyClaimRepository::class);
        $redis = $this->createMock(RedisCacheRepository::class);

        return [$user, $client, $resolver, $policyRepo, $redis];
    }

    private function serviceFormatClient(): Client
    {
        return Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->iamService('Skbc')
            ->build();
    }

    private function makeController(
        ?ClientResourcesRequestResolver $resolver = null,
        ?UserLoginClientRepository $metadataRepository = null,
        ?RedisCacheRepository $redis = null,
        ?UserPolicyClaimRepository $policyRepository = null,
    ): ClientResourcesController {
        return new ClientResourcesController(
            $this->createMock(LoggerInterface::class),
            $resolver ?? new ClientResourcesRequestResolver(
                $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class),
                $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class),
            ),
            $policyRepository ?? $this->createMock(UserPolicyClaimRepository::class),
            $metadataRepository ?? $this->createMock(UserLoginClientRepository::class),
            $redis ?? $this->createMock(RedisCacheRepository::class),
        );
    }

    /**
     * @return array{0: ResponseInterface, 1: StreamInterface}
     */
    private function mockJsonResponse(): array
    {
        $stream = $this->createMock(StreamInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();
        $response->method('getBody')->willReturn($stream);

        return [$response, $stream];
    }

    private function mockResponse(): ResponseInterface
    {
        return $this->mockJsonResponse()[0];
    }
}

class ClientTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'clients';
        $this->fields = [
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::STRING)->build(),
            'client_secret' => FieldDefinition::builder()->name('client_secret')->type(FieldType::STRING)->build(),
            'name' => FieldDefinition::builder()->name('name')->type(FieldType::STRING)->build(),
            'redirect_uri' => FieldDefinition::builder()->name('redirect_uri')->type(FieldType::STRING)->build(),
            'is_confidential' => FieldDefinition::builder()->name('is_confidential')->type(FieldType::BOOL)->build(),
            'is_dev' => FieldDefinition::builder()->name('is_dev')->type(FieldType::BOOL)->build(),
            'iam_service' => FieldDefinition::builder()->name('iam_service')->type(FieldType::STRING)->build(),
            'iam_service_format' => FieldDefinition::builder()->name('iam_service_format')->type(FieldType::STRING)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}
