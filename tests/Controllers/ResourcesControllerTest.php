<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Services\OrkService;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\UserAuthority;
use Amtgard\SetQueue\PubSubQueue;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class TestResourcesUserEntity extends UserEntity
{
    private int $testUserId;
    private string $testEmail;
    private string $testFullName;

    public function __construct(int $userId, string $email, string $fullName)
    {
        $this->testUserId = $userId;
        $this->testEmail = $email;
        $this->testFullName = $fullName;
    }

    public function getUserId(): string { return (string) $this->testUserId; }
    public function getEmail(): string { return $this->testEmail; }
    public function getFullName(): string { return $this->testFullName; }
    public function getId(): int { return $this->testUserId; }
}

class TestUserOrkProfileEntity extends UserOrkProfileEntity
{
    public function getMundaneId(): int { return 1001; }
    public function getUsername(): string { return 'orkuser'; }
    public function getPersona(): string { return 'Persona Name'; }
    public function getSuspended(): int { return 0; }
    public function getSuspendedAt(): ?\DateTime { return null; }
    public function getSuspendedUntil(): ?\DateTime { return null; }
    public function getParkId(): ?int { return 5; }
    public function getParkName(): ?string { return 'Park Name'; }
    public function getKingdomId(): ?int { return 2; }
    public function getKingdomName(): ?string { return 'Kingdom Name'; }
    public function getImage(): ?string { return 'image.jpg'; }
    public function getHeraldry(): ?string { return 'heraldry.jpg'; }
    public function getDuesThrough(): ?\DateTime { return null; }
    public function getOrkToken(): string { return 'ork-token-123'; }
}

class ResourcesControllerTest extends TestCase
{
    private $em;
    private $logger;
    private $twig;
    private $clientRepository;
    private $redisPubSubQueue;
    private $pubSubQueueHandle;
    private $redisCacheRepository;
    private $database;
    private $orkService;
    private $orkProfileRepository;
    private $userClientAuthorizationRepository;
    private $userLoginRepository;
    private $amtgardIdpJwt;
    private $userAuthority;
    private $request;
    private $response;
    private $stream;
    private $controller;
    private $userEntity;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        $this->em = $this->createMock(EntityManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->twig = $this->createMock(TwigEnvironment::class);
        
        // Mock concrete ClientRepository instead of League's interface
        $this->clientRepository = $this->createMock(ClientRepository::class);
        
        $this->redisPubSubQueue = $this->createMock(PubSubQueue::class);
        $this->pubSubQueueHandle = $this->createMock(PubSubQueueHandle::class);
        $this->redisCacheRepository = $this->createMock(RedisCacheRepository::class);
        $this->database = $this->createMock(Database::class);
        $this->orkService = $this->createMock(OrkService::class);
        $this->orkProfileRepository = $this->createMock(UserOrkProfileRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userClientAuthorizationRepository = $this->createMock(UserClientAuthorizationRepository::class);
        $this->userLoginRepository = $this->createMock(UserLoginRepository::class);
        $this->amtgardIdpJwt = $this->createMock(AmtgardIdpJwt::class);
        $this->userAuthority = $this->createMock(UserAuthority::class);

        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        $this->userEntity = new TestResourcesUserEntity(123, 'test@example.com', 'John Doe');
        
        // Instantiate OAuthUser using its builder instead of mocking it
        $oauthUser = OAuthUser::builder()->userEntity($this->userEntity)->build();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('getUserEntityById')->willReturn($oauthUser);

        $this->em->method('getRepository')
            ->with(UserRepository::class)
            ->willReturn($userRepository);

        EntityManager::configure($this->em, true);

        $this->controller = new ResourcesController(
            $this->em,
            $this->logger,
            $this->twig,
            $this->clientRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle,
            $this->redisCacheRepository,
            $this->database,
            $this->orkService,
            $this->orkProfileRepository,
            $this->userRepository,
            $this->userClientAuthorizationRepository,
            $this->userLoginRepository,
            $this->amtgardIdpJwt,
            $this->userAuthority
        );
    }

    public function testProfileWithoutAuthenticatedUser(): void
    {
        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('profile.twig', $this->callback(function ($context) {
                return $context['isAdmin'] === false && empty($context['authorizations']);
            }))
            ->willReturn('profile view');

        $this->stream->expects($this->once())
            ->method('write')
            ->with('profile view');

        $result = $this->controller->profile($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testProfilePassesPendingRedirectFlagWhenOAuthRedirectStored(): void
    {
        $_SESSION['user_id'] = 123;
        $_SESSION['redirect'] = '/oauth/authorize?client_id=ork-app';

        $this->request->method('getQueryParams')->willReturn([]);
        $this->userAuthority->method('isAdmin')->willReturn(false);
        $this->clientRepository->method('findActiveClientsForUser')->willReturn([]);
        $this->orkProfileRepository->method('findByUserId')->willReturn(null);
        $this->userLoginRepository->method('getAllLoginsForUser')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('profile.twig', $this->callback(function (array $context): bool {
                return $context['pendingRedirect'] === true && $context['orkProfile'] === null;
            }))
            ->willReturn('profile view');

        $this->stream->method('write')->with('profile view');
        $this->controller->profile($this->request, $this->response);
    }

    public function testProfileWithAuthenticatedUser(): void
    {
        $_SESSION['user_id'] = 123;
        $_SESSION['avatar_url'] = 'http://avatar';

        $this->request->expects($this->once())
            ->method('getQueryParams')
            ->willReturn(['error' => 'some_error', 'success' => 'some_success']);

        $this->userAuthority->expects($this->once())
            ->method('isAdmin')
            ->with($this->userEntity)
            ->willReturn(true);

        $this->clientRepository->expects($this->once())
            ->method('findActiveClientsForUser')
            ->with(123)
            ->willReturn(['client1']);

        $orkProfile = new TestUserOrkProfileEntity();
        $this->orkProfileRepository->expects($this->once())
            ->method('findByUserId')
            ->with(123)
            ->willReturn($orkProfile);

        $this->userLoginRepository->expects($this->once())
            ->method('getAllLoginsForUser')
            ->with(123)
            ->willReturn(['login1']);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('profile.twig', $this->callback(function ($context) {
                return $context['isAdmin'] === true &&
                       $context['avatarUrl'] === 'http://avatar' &&
                       $context['error'] === 'some_error' &&
                       $context['success'] === 'some_success';
            }))
            ->willReturn('profile view HTML');

        $this->stream->expects($this->once())
            ->method('write')
            ->with('profile view HTML');

        $result = $this->controller->profile($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testGetJwtUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(401)
            ->willReturnSelf();

        $result = $this->controller->getJwt($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testGetJwtAuthenticated(): void
    {
        $_SESSION['user_id'] = 123;

        $this->amtgardIdpJwt->expects($this->once())
            ->method('buildAuthorizationTokens')
            ->with($this->userEntity)
            ->willReturn(['jwt' => 'jwt-val', 'compact_jwt' => 'compact-val']);

        $this->redisCacheRepository->expects($this->once())
            ->method('cacheValidatedUser')
            ->with('123', 'test@example.com', 'jwt-val');

        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['jwt' => 'jwt-val', 'compact_jwt' => 'compact-val']));

        $result = $this->controller->getJwt($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testUserInfoUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(401)
            ->willReturnSelf();

        $result = $this->controller->userInfo($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testUserInfoAuthenticatedWithOrk(): void
    {
        $_SESSION['user_id'] = 123;

        $this->amtgardIdpJwt->expects($this->never())
            ->method('buildAuthorizationJwt');

        $orkProfile = new TestUserOrkProfileEntity();
        $this->orkProfileRepository->expects($this->once())
            ->method('findByUserId')
            ->with(123)
            ->willReturn($orkProfile);

        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function ($json) {
                $data = json_decode($json, true);
                return $data['id'] === '123' &&
                       $data['email'] === 'test@example.com' &&
                       !isset($data['jwt']) &&
                       $data['ork_profile']['username'] === 'orkuser';
            }));

        $result = $this->controller->userInfo($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testUserInfoAuthenticatedWithoutOrkProfile(): void
    {
        $_SESSION['user_id'] = 123;

        $this->amtgardIdpJwt->expects($this->never())
            ->method('buildAuthorizationJwt');
        $this->orkProfileRepository->expects($this->once())
            ->method('findByUserId')
            ->with(123)
            ->willReturn(null);
        $this->stream->expects($this->once())
            ->method('write')
            ->with($this->callback(function (string $json): bool {
                $data = json_decode($json, true);
                return $data['id'] === '123'
                    && $data['email'] === 'test@example.com'
                    && !isset($data['jwt'])
                    && !isset($data['ork_profile']);
            }));

        $this->assertSame($this->response, $this->controller->userInfo($this->request, $this->response));
    }

    public function testAuthorizationsUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withStatus')
            ->with(401)
            ->willReturnSelf();

        $result = $this->controller->authorizations($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testAuthorizationsAuthenticated(): void
    {
        $_SESSION['user_id'] = 123;

        $this->clientRepository->expects($this->once())
            ->method('findActiveClientsForUser')
            ->with(123)
            ->willReturn(['client1']);

        $this->stream->expects($this->once())
            ->method('write')
            ->with(json_encode(['client1']));

        $result = $this->controller->authorizations($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testLinkOrkAccountUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/auth/login')
            ->willReturnSelf();

        $result = $this->controller->linkOrkAccount($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testLinkOrkAccountCompletesPendingOAuthRedirect(): void
    {
        $_SESSION['user_id'] = 123;
        $_SESSION['redirect'] = '/oauth/authorize?client_id=ork-app';

        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
        $this->orkService->method('authorize')->willReturn(['Token' => 'token-123', 'UserId' => 1001]);
        $this->orkService->method('getPlayer')->willReturn(['ParkId' => 5, 'username' => 'testuser']);
        $this->orkService->method('getParkShortInfo')->willReturn(['park_info']);
        $this->orkProfileRepository->expects($this->once())->method('saveOrUpdateProfile');
        $this->amtgardIdpJwt->expects($this->once())
            ->method('buildAuthorizationJwt')
            ->with($this->userEntity)
            ->willReturn('linked-user-jwt');

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/oauth/authorize?client_id=ork-app?jwt=linked-user-jwt')
            ->willReturnSelf();

        $this->controller->linkOrkAccount($this->request, $this->response);
        $this->assertArrayNotHasKey('redirect', $_SESSION);
    }

    public function testLinkOrkAccountSuccess(): void
    {
        $_SESSION['user_id'] = 123;

        $this->request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['username' => 'testuser', 'password' => 'testpass']);

        $this->orkService->expects($this->once())
            ->method('authorize')
            ->with('testuser', 'testpass')
            ->willReturn(['Token' => 'token-123', 'UserId' => 1001]);

        $this->orkService->expects($this->once())
            ->method('getPlayer')
            ->with('token-123', 1001)
            ->willReturn(['ParkId' => 5, 'username' => 'testuser']);

        $this->orkService->expects($this->once())
            ->method('getParkShortInfo')
            ->with(5)
            ->willReturn(['park_info']);

        $this->orkProfileRepository->expects($this->once())
            ->method('saveOrUpdateProfile')
            ->with(['ParkId' => 5, 'username' => 'testuser'], ['park_info'], 'token-123', 123);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=linked')
            ->willReturnSelf();

        $result = $this->controller->linkOrkAccount($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testLinkOrkAccountFailure(): void
    {
        $_SESSION['user_id'] = 123;

        $this->request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['username' => 'testuser', 'password' => 'testpass']);

        $this->orkService->expects($this->once())
            ->method('authorize')
            ->willReturn(null);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_auth_failed')
            ->willReturnSelf();

        $result = $this->controller->linkOrkAccount($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testLinkOrkAccountRedirectsWhenPlayerFetchFails(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
        $this->orkService->method('authorize')->willReturn(['Token' => 'token-123', 'UserId' => 1001]);
        $this->orkService->method('getPlayer')->with('token-123', 1001)->willReturn(null);
        $this->orkService->expects($this->never())->method('getParkShortInfo');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_player_failed')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkAccount($this->request, $this->response));
    }

    public function testRefreshOrkAccountRedirectsWhenUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/auth/login')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->refreshOrkAccount($this->request, $this->response));
    }

    public function testRefreshOrkAccountRedirectsWhenProfileMissing(): void
    {
        $_SESSION['user_id'] = 123;
        $this->orkProfileRepository->method('findByUserId')->with(123)->willReturn(null);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=no_profile')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->refreshOrkAccount($this->request, $this->response));
    }

    public function testRefreshOrkAccountRedirectsWhenPlayerFetchFails(): void
    {
        $_SESSION['user_id'] = 123;
        $this->orkProfileRepository->method('findByUserId')->with(123)->willReturn(new TestUserOrkProfileEntity());
        $this->orkService->method('getPlayer')->with('ork-token-123', 1001)->willReturn(null);
        $this->orkService->expects($this->never())->method('getParkShortInfo');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_refresh_failed')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->refreshOrkAccount($this->request, $this->response));
    }

    public function testRefreshOrkAccountSuccess(): void
    {
        $_SESSION['user_id'] = 123;

        $orkProfile = new TestUserOrkProfileEntity();
        $this->orkProfileRepository->expects($this->once())
            ->method('findByUserId')
            ->with(123)
            ->willReturn($orkProfile);

        $this->orkService->expects($this->once())
            ->method('getPlayer')
            ->with('ork-token-123', 1001)
            ->willReturn(['ParkId' => 5, 'username' => 'testuser']);

        $this->orkService->expects($this->once())
            ->method('getParkShortInfo')
            ->with(5)
            ->willReturn(['park_info']);

        $this->orkProfileRepository->expects($this->once())
            ->method('saveOrUpdateProfile')
            ->with(['ParkId' => 5, 'username' => 'testuser'], ['park_info'], 'ork-token-123', 123);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=refreshed')
            ->willReturnSelf();

        $result = $this->controller->refreshOrkAccount($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testRefreshOrkAccountSuccessWithNoPark(): void
    {
        $_SESSION['user_id'] = 123;

        $orkProfile = new TestUserOrkProfileEntity();
        $this->orkProfileRepository->expects($this->once())
            ->method('findByUserId')
            ->with(123)
            ->willReturn($orkProfile);

        $playerData = [
            'MundaneId' => 1001,
            'ParkId' => 0,
            'KingdomId' => 0,
            'UserName' => 'admin',
        ];

        $this->orkService->expects($this->once())
            ->method('getPlayer')
            ->with('ork-token-123', 1001)
            ->willReturn($playerData);

        $this->orkService->expects($this->never())->method('getParkShortInfo');

        $this->orkProfileRepository->expects($this->once())
            ->method('saveOrUpdateProfile')
            ->with($playerData, null, 'ork-token-123', 123);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=refreshed')
            ->willReturnSelf();

        $result = $this->controller->refreshOrkAccount($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testRevokeAuthorizationSuccess(): void
    {
        $_SESSION['user_id'] = 123;

        $this->request->expects($this->once())
            ->method('getParsedBody')
            ->willReturn(['client_id' => '456']);

        $this->userClientAuthorizationRepository->expects($this->once())
            ->method('revokeAuthorization')
            ->with('123', 456);

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=revoked')
            ->willReturnSelf();

        $result = $this->controller->revokeAuthorization($this->request, $this->response);
        $this->assertSame($this->response, $result);
    }

    public function testRevokeAuthorizationRedirectsWhenUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/auth/login')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->revokeAuthorization($this->request, $this->response));
    }

    public function testRevokeAuthorizationRejectsInvalidClientId(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['client_id' => '0']);
        $this->userClientAuthorizationRepository->expects($this->never())->method('revokeAuthorization');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=invalid_client')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->revokeAuthorization($this->request, $this->response));
    }

    public function testLinkOrkProfileRejectsInvalidBody(): void
    {
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => '', 'mundane_id' => 0]);
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('mundane_id'));

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileRejectsUnknownUser(): void
    {
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => 'uuid-missing', 'mundane_id' => 1001]);
        $this->userRepository->method('findUserByUserId')->with('uuid-missing')->willReturn(null);
        $this->response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('unknown idp_user_id'));

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileReportsConflict(): void
    {
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => 'uuid-user', 'mundane_id' => 1001]);
        $this->userRepository->method('findUserByUserId')->with('uuid-user')->willReturn($this->userEntity);
        $this->orkProfileRepository->method('linkExistingUserToMundane')
            ->with(123, 1001, 'mirror')
            ->willThrowException(new \RuntimeException('conflict: already linked'));
        $this->response->expects($this->once())->method('withStatus')->with(409)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('different mundane_id'));

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileReturnsNoContentOnSuccess(): void
    {
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => ' uuid-user ', 'mundane_id' => '1001']);
        $this->userRepository->method('findUserByUserId')->with('uuid-user')->willReturn($this->userEntity);
        $this->orkProfileRepository->expects($this->once())
            ->method('linkExistingUserToMundane')
            ->with(123, 1001, 'mirror');
        $this->response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }
}
