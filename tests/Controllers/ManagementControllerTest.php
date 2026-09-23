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
use Amtgard\IdP\Controllers\Management\ManagementController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Entities\Repository\ClientAccess;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class TestTableSchema extends TableSchema
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

class ManagementControllerTest extends TestCase
{
    private $logger;
    private $twig;
    private $entityManager;
    private $accessTokens;
    private $refreshTokens;
    private $authCodes;
    private $clientRepository;
    private $orkLinkTokenService;
    private $clientAccessRepository;
    private $userRepository;
    private $request;
    private $response;
    private $stream;
    private $controller;

    protected function setUp(): void
    {
        $database = $this->createMock(Database::class);
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $repositoryPolicy = $this->createMock(RepositoryPolicy::class);

        $tableSchema = new TestTableSchema();
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn($tableSchema);

        $em = EntityManager::builder()
            ->database($database)
            ->dataAccessPolicy($dataAccessPolicy)
            ->repositoryPolicy($repositoryPolicy)
            ->build();
        EntityManager::configure($em, true);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->entityManager = $em;
        $this->accessTokens = $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository::class);
        $this->refreshTokens = $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository::class);
        $this->authCodes = $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\AuthCodeRepository::class);
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->orkLinkTokenService = $this->createMock(\Amtgard\IdP\Services\OrkLinkTokenService::class);
        $this->clientAccessRepository = $this->createMock(ClientAccessRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        $this->controller = new ManagementController(
            $this->logger,
            $this->twig,
            $this->accessTokens,
            $this->refreshTokens,
            $this->authCodes,
            $this->clientRepository,
            $this->orkLinkTokenService,
            $this->clientAccessRepository,
            $this->userRepository
        );
    }

    public function testCleanTokensSuccess(): void
    {
        $this->accessTokens->expects($this->once())->method('deleteExpiredTokens');
        $this->refreshTokens->expects($this->once())->method('deleteExpiredTokens');
        $this->refreshTokens->expects($this->once())->method('deleteOrphanedRefreshTokens');
        $this->authCodes->expects($this->once())->method('deleteExpiredAuthCodes');
        $this->orkLinkTokenService->expects($this->once())->method('cleanExpiredJti');

        $this->stream->expects($this->once())
            ->method('write')
            ->with('Tokens cleaned successfully');

        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(200)
            ->willReturnSelf();

        $result = $this->controller->cleanTokens($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testCleanTokensFailure(): void
    {
        $this->accessTokens->expects($this->once())
            ->method('deleteExpiredTokens')
            ->willThrowException(new \Exception('Database error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Token cleanup failed'));

        $this->stream->expects($this->once())
            ->method('write')
            ->with('Token cleanup failed');

        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(500)
            ->willReturnSelf();

        $result = $this->controller->cleanTokens($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testListClients(): void
    {
        $client = new class extends Client {
            public function getId(): mixed { return 9; }
            public function getIdentifier(): string { return 'client-1'; }
            public function getClientSecret(): string { return 'secret-1'; }
            public function getName(): string { return 'Name 1'; }
            public function getRedirectUri(): string { return 'http://redirect-1'; }
            public function getIsConfidential(): bool { return false; }
            public function getIsDev(): bool { return false; }
            public function getIamService(): ?string { return null; }
            public function getIamServiceFormat(): ?string { return null; }
        };

        $access = new class extends ClientAccess {
            public function getUserId(): int { return 3; }
        };
        $user = new class extends UserEntity {
            public function getId(): int { return 3; }
            public function getEmail(): string { return 'owner@example.com'; }
        };

        $this->clientRepository->expects($this->once())
            ->method('getAllClients')
            ->willReturn([$client]);
        $this->clientAccessRepository->expects($this->once())
            ->method('findByClientId')
            ->with(9)
            ->willReturn([$access]);
        $this->userRepository->expects($this->once())
            ->method('findUserById')
            ->with(3)
            ->willReturn($user);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('management/clients.twig', $this->callback(function ($context) {
                return count($context['clients']) === 1
                    && $context['clients'][0]['identifier'] === 'client-1'
                    && $context['viewMode'] === 'admin'
                    && $context['clients'][0]['accessUsers'][0]['email'] === 'owner@example.com';
            }))
            ->willReturn('clients HTML');

        $this->stream->expects($this->once())
            ->method('write')
            ->with('clients HTML');

        $result = $this->controller->listClients($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testCreateClient(): void
    {
        $this->request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'client_id' => 'new-client',
                'client_secret' => 'secret-key',
                'name' => 'New Client',
                'redirect_uri' => 'http://new-redirect',
                'is_confidential' => 'on'
            ]);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/management/clients')
            ->willReturnSelf();

        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->createClient($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testUpdateClient(): void
    {
        $client = Client::builder()
            ->identifier('old-client')
            ->clientSecret('old-secret')
            ->name('Old Name')
            ->redirectUri('http://old-redirect')
            ->isConfidential(false)
            ->isDev(false)
            ->build();

        $this->clientRepository->expects($this->once())
            ->method('fetch')
            ->with(5)
            ->willReturn($client);

        $this->request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn([
                'client_id' => 'updated-client',
                'client_secret' => 'new-secret',
                'name' => 'Updated Name',
                'redirect_uri' => 'http://new-redirect'
            ]);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/management/clients')
            ->willReturnSelf();

        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->updateClient($this->request, $this->response, 5);
        $this->assertSame($this->response, $result);
        
        // Use AARO Data getters (magic methods mapped in Client/RepositoryEntity)
        $this->assertEquals('updated-client', $client->getIdentifier());
        $this->assertEquals('new-secret', $client->getClientSecret());
    }

    public function testSearchUsersReturnsEmptyWhenQueryTooShort(): void
    {
        $this->request->method('getQueryParams')->willReturn(['q' => 'a']);
        $this->userRepository->expects($this->never())->method('searchByEmailPrefix');
        $this->stream->expects($this->once())->method('write')->with(json_encode(['users' => []]));

        $result = $this->controller->searchUsers($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testSearchUsersReturnsMatches(): void
    {
        $this->request->method('getQueryParams')->willReturn(['q' => 'own']);
        $this->userRepository->expects($this->once())
            ->method('searchByEmailPrefix')
            ->with('own')
            ->willReturn([['id' => 3, 'email' => 'owner@example.com']]);
        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['users' => [['id' => 3, 'email' => 'owner@example.com']]]));

        $this->controller->searchUsers($this->request, $this->response);
    }

    public function testAddClientAccessGrantsExistingUser(): void
    {
        $client = Client::builder()->identifier('app')->build();
        $user = new class extends UserEntity {
            public function getId(): int { return 3; }
            public function getEmail(): string { return 'owner@example.com'; }
        };

        $this->clientRepository->expects($this->once())->method('fetch')->with(9)->willReturn($client);
        $this->request->method('getParsedBody')->willReturn(['user_id' => 3]);
        $this->userRepository->expects($this->once())->method('findUserById')->with(3)->willReturn($user);
        $this->clientAccessRepository->expects($this->once())->method('grant')->with(9, 3);
        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['id' => 3, 'email' => 'owner@example.com']));

        $this->controller->addClientAccess($this->request, $this->response, 9);
    }

    public function testAddClientAccessReturnsNotFoundWhenUserMissing(): void
    {
        $client = Client::builder()->identifier('app')->build();
        $this->clientRepository->method('fetch')->willReturn($client);
        $this->request->method('getParsedBody')->willReturn(['email' => 'missing@example.com']);
        $this->userRepository->method('getUserByEmail')->willReturn(null);
        $this->clientAccessRepository->expects($this->never())->method('grant');
        $this->response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();

        $this->controller->addClientAccess($this->request, $this->response, 9);
    }

    public function testRemoveClientAccessRevokesGrant(): void
    {
        $client = Client::builder()->identifier('app')->build();
        $this->clientRepository->expects($this->once())->method('fetch')->with(9)->willReturn($client);
        $this->clientAccessRepository->expects($this->once())->method('revoke')->with(9, 3);
        $this->stream->expects($this->once())->method('write')->with(json_encode(['ok' => true]));

        $this->controller->removeClientAccess($this->request, $this->response, 9, 3);
    }
}
