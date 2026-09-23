<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\ActiveRecordOrm\Entity\Policy\RepositoryPolicy;
use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Interface\DataAccessPolicy;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;
use Amtgard\IdP\Controllers\Management\ClientAccessController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Twig\Environment as TwigEnvironment;

class ClientAccessControllerTest extends TestCase
{
    private $twig;
    private $clientRepository;
    private $clientAccessRepository;
    private $currentUserResolver;
    private $request;
    private $response;
    private $stream;
    private ClientAccessController $controller;
    private UserEntity $user;

    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createMock(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new class extends TableSchema {
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
        });
        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createMock(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createMock(RepositoryPolicy::class))
                ->build(),
            true
        );

        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->clientAccessRepository = $this->createMock(ClientAccessRepository::class);
        $this->currentUserResolver = $this->createMock(CurrentUserResolverInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        $this->user = new class extends UserEntity {
            public function getId(): int { return 3; }
        };
        $this->currentUserResolver->method('resolve')->willReturn($this->user);

        $this->controller = new ClientAccessController(
            $this->twig,
            $this->clientRepository,
            $this->clientAccessRepository,
            $this->currentUserResolver
        );
    }

    public function testListClientsRendersOnlyGrantedClients(): void
    {
        $client = new class extends Client {
            public function getId(): mixed { return 9; }
            public function getIdentifier(): string { return 'app'; }
            public function getClientSecret(): string { return 'secret'; }
            public function getName(): string { return 'App'; }
            public function getRedirectUri(): string { return 'http://cb'; }
            public function getIsConfidential(): bool { return true; }
            public function getIsDev(): bool { return false; }
            public function getIamService(): ?string { return null; }
            public function getIamServiceFormat(): ?string { return null; }
        };

        $this->clientRepository->expects($this->once())
            ->method('findClientsGrantedToUser')
            ->with(3)
            ->willReturn([$client]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('management/clients.twig', $this->callback(function (array $context): bool {
                return $context['viewMode'] === 'operator'
                    && count($context['clients']) === 1
                    && $context['clients'][0]['identifier'] === 'app';
            }))
            ->willReturn('operator clients');

        $this->stream->expects($this->once())->method('write')->with('operator clients');

        $this->assertSame($this->response, $this->controller->listClients($this->request, $this->response));
    }

    public function testUpdateRedirectChangesOnlyRedirectUri(): void
    {
        $client = Client::builder()
            ->identifier('app')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://old')
            ->isConfidential(true)
            ->isDev(false)
            ->build();

        $this->clientRepository->expects($this->once())->method('fetch')->with(9)->willReturn($client);
        $this->clientAccessRepository->expects($this->once())->method('hasAccess')->with(9, 3)->willReturn(true);
        $this->request->method('getParsedBody')->willReturn([
            'redirect_uri' => 'http://new',
            'name' => 'Hacked',
            'client_secret' => 'stolen',
        ]);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/clients')
            ->willReturnSelf();

        $this->controller->updateRedirect($this->request, $this->response, 9);

        $this->assertSame('http://new', $client->getRedirectUri());
        $this->assertSame('App', $client->getName());
        $this->assertSame('secret', $client->getClientSecret());
    }

    public function testUpdateRedirectRejectsUsersWithoutAccess(): void
    {
        $client = Client::builder()->identifier('app')->redirectUri('http://old')->build();
        $this->clientRepository->method('fetch')->willReturn($client);
        $this->clientAccessRepository->method('hasAccess')->willReturn(false);
        $this->request->method('getParsedBody')->willReturn(['redirect_uri' => 'http://new']);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile')
            ->willReturnSelf();

        $this->controller->updateRedirect($this->request, $this->response, 9);

        $this->assertSame('http://old', $client->getRedirectUri());
    }
}
