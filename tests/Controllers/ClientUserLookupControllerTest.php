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
use Amtgard\IdP\Controllers\Resource\ClientIamRequestInterpreter;
use Amtgard\IdP\Controllers\Resource\ClientUserLookupController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

final class ClientUserLookupControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $dataAccessPolicy = $this->createStub(DataAccessPolicy::class);
        $dataAccessPolicy->method('applyTableSchemaPolicy')->willReturn(new ClientUserLookupTableSchema());

        EntityManager::configure(
            EntityManager::builder()
                ->database($this->createStub(Database::class))
                ->dataAccessPolicy($dataAccessPolicy)
                ->repositoryPolicy($this->createStub(RepositoryPolicy::class))
                ->build(),
            true
        );
    }

    public function testResolveUserByEmailReturnsThePublicId(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('getUserByEmail')
            ->with('player@amtgard.com')
            ->willReturn($this->emailLookupUser());

        [$response, $stream] = $this->jsonResponse();
        $response->expects($this->once())->method('withStatus')->with(200)->willReturnSelf();
        $response->expects($this->once())->method('withHeader')->with('Content-Type', 'application/json')->willReturnSelf();
        $stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $payload = json_decode($json, true);

                return ($payload['idp_user_id'] ?? null) === 'uuid-123'
                    && ($payload['email'] ?? null) === 'player@amtgard.com';
            }));

        $this->assertSame(
            $response,
            $this->controller($users, $this->createStub(LoggerInterface::class))
                ->resolveUserByEmail($this->request('  player@amtgard.com  '), $response)
        );
    }

    public function testResolveUserByEmailRejectsAMissingEmail(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('getUserByEmail');
        [$response, $stream] = $this->jsonResponse();
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $stream->expects($this->once())->method('write')->with($this->stringContains('email is required'));

        $this->assertSame(
            $response,
            $this->controller($users, $this->createStub(LoggerInterface::class))
                ->resolveUserByEmail($this->request(null), $response)
        );
    }

    public function testResolveUserByEmailRejectsAMalformedEmail(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('getUserByEmail');
        [$response, $stream] = $this->jsonResponse();
        $response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $stream->expects($this->once())->method('write')->with($this->stringContains('email is required'));

        $this->assertSame(
            $response,
            $this->controller($users, $this->createStub(LoggerInterface::class))
                ->resolveUserByEmail($this->request('not-an-email'), $response)
        );
    }

    public function testResolveUserByEmailReturns404WhenTheAddressIsUnknown(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('getUserByEmail')->with('missing@amtgard.com')->willReturn(null);
        [$response, $stream] = $this->jsonResponse();
        $response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();
        $stream->expects($this->once())->method('write')->with($this->stringContains('unknown email'));

        $this->assertSame(
            $response,
            $this->controller($users, $this->createStub(LoggerInterface::class))
                ->resolveUserByEmail($this->request('missing@amtgard.com'), $response)
        );
    }

    public function testResolveUserByEmailLogsAResolvedUser(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('getUserByEmail')->willReturn($this->emailLookupUser());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('client user lookup by email resolved', [
                'client_id' => 'app-client',
                'idp_user_id' => 'uuid-123',
            ]);

        $this->controller($users, $logger)
            ->resolveUserByEmail($this->request('player@amtgard.com'), $this->silentResponse());
    }

    public function testResolveUserByEmailLogsAMissingEmail(): void
    {
        $this->assertRejectionLog('   ', 'missing_email');
    }

    public function testResolveUserByEmailLogsAnInvalidEmail(): void
    {
        $this->assertRejectionLog('not-an-email', 'invalid_email');
    }

    public function testResolveUserByEmailLogsAnUnknownEmail(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('getUserByEmail')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('client user lookup by email rejected', [
                'client_id' => 'app-client',
                'reason' => 'unknown_email',
            ]);

        $this->controller($users, $logger)
            ->resolveUserByEmail($this->request('missing@amtgard.com'), $this->silentResponse());
    }

    private function assertRejectionLog(string $email, string $reason): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('client user lookup by email rejected', [
                'client_id' => 'app-client',
                'reason' => $reason,
            ]);

        $this->controller($this->createStub(UserRepository::class), $logger)
            ->resolveUserByEmail($this->request($email), $this->silentResponse());
    }

    private function controller(UserRepository $users, LoggerInterface $logger): ClientUserLookupController
    {
        return new ClientUserLookupController(
            $logger,
            new ClientIamRequestInterpreter(
                new ClientResourcesRequestResolver($users, $this->createStub(UserLoginRepository::class)),
            ),
        );
    }

    private function request(?string $email): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($email === null ? [] : ['email' => $email]);
        $request->method('getAttribute')->willReturn(Client::builder()
            ->identifier('app-client')
            ->clientSecret('secret')
            ->name('App')
            ->redirectUri('http://localhost/cb')
            ->isConfidential(true)
            ->build());

        return $request;
    }

    private function emailLookupUser(): UserEntity
    {
        return new class extends UserEntity {
            public function getUserId(): string
            {
                return 'uuid-123';
            }

            public function getEmail(): ?string
            {
                return 'player@amtgard.com';
            }
        };
    }

    private function silentResponse(): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();
        $response->method('getBody')->willReturn($this->createStub(StreamInterface::class));

        return $response;
    }

    /**
     * @return array{0: ResponseInterface, 1: StreamInterface}
     */
    private function jsonResponse(): array
    {
        $stream = $this->createMock(StreamInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();
        $response->method('getBody')->willReturn($stream);

        return [$response, $stream];
    }
}

final class ClientUserLookupTableSchema extends TableSchema
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
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}
