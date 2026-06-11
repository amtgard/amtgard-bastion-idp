<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\ClientResourcesController;
use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class ClientResourcesControllerTest extends TestCase
{
    public function testUpsertUserMetadataReturns204(): void
    {
        $user = new class extends UserEntity {
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

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findUserByUserId')->with('uuid-123')->willReturn($user);

        $userLoginRepository = $this->createMock(UserLoginRepository::class);
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
                ClientMetadataValidator::ENCODING_JSON
            );

        $redis = $this->createMock(RedisCacheRepository::class);
        $redis->expects($this->once())->method('invalidateUser')->with('uuid-123');

        $controller = new ClientResourcesController(
            $this->createMock(LoggerInterface::class),
            new ClientResourcesRequestResolver($userRepository, $userLoginRepository),
            $this->createMock(UserPolicyClaimRepository::class),
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

        $stream = $this->createMock(StreamInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();
        $response->method('getBody')->willReturn($stream);

        $result = $controller->upsertUserMetadata($request, $response);
        $this->assertSame($response, $result);
    }
}
