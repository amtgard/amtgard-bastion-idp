<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\MailboxChallengeEntity;
use Amtgard\IdP\Tests\Support\TestMailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Services\IdpEmailMigrationService;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Services\OrkService;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
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
    private $logger;
    private $twig;
    private $clientRepository;
    private $redisPubSubQueue;
    private $pubSubQueueHandle;
    private $database;
    private $orkService;
    private $orkProfileRepository;
    private $userRepository;
    private $userClientAuthorizationRepository;
    private $clientAccessRepository;
    private $userLoginRepository;
    private $amtgardIdpJwt;
    private $userAuthority;
    private $currentUserResolver;
    private $mailboxChallenges;
    private $orkLinkTokenService;
    private $emailMigration;
    private $request;
    private $response;
    private $stream;
    private $controller;
    private $userEntity;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->twig = $this->createMock(TwigEnvironment::class);
        
        // Mock concrete ClientRepository instead of League's interface
        $this->clientRepository = $this->createMock(ClientRepository::class);
        
        $this->redisPubSubQueue = $this->createMock(PubSubQueue::class);
        $this->pubSubQueueHandle = $this->createMock(PubSubQueueHandle::class);
        $this->database = $this->createMock(Database::class);
        $this->orkService = $this->createMock(OrkService::class);
        $this->orkProfileRepository = $this->createMock(UserOrkProfileRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userClientAuthorizationRepository = $this->createMock(UserClientAuthorizationRepository::class);
        $this->clientAccessRepository = $this->createMock(ClientAccessRepository::class);
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

        $this->currentUserResolver = $this->createMock(CurrentUserResolverInterface::class);
        $this->currentUserResolver->method('resolve')->willReturnCallback(function (): ?UserEntity {
            return isset($_SESSION['user_id']) ? $this->userEntity : null;
        });
        $this->mailboxChallenges = $this->createMock(MailboxChallengeService::class);
        $this->orkLinkTokenService = $this->createMock(OrkLinkTokenService::class);
        $this->emailMigration = $this->createMock(IdpEmailMigrationService::class);

        $this->controller = new ResourcesController(
            $this->logger,
            $this->twig,
            $this->clientRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle,
            $this->database,
            $this->orkService,
            $this->orkProfileRepository,
            $this->userRepository,
            $this->userClientAuthorizationRepository,
            $this->userLoginRepository,
            $this->amtgardIdpJwt,
            $this->userAuthority,
            $this->currentUserResolver,
            ResourcesUserinfoService::builder()->orkProfileRepository($this->orkProfileRepository)->build(),
            $this->clientAccessRepository,
            $this->mailboxChallenges,
            $this->orkLinkTokenService,
            $this->emailMigration,
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
                return $context['isAdmin'] === false
                    && $context['hasClientAccess'] === false
                    && empty($context['authorizations']);
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

        $this->clientAccessRepository->expects($this->once())
            ->method('userHasAnyAccess')
            ->with(123)
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
                       $context['hasClientAccess'] === true &&
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
        $this->orkService->method('resolveParkDataFromPlayer')->willReturn(['park_info']);
        $this->orkProfileRepository->expects($this->once())->method('saveOrUpdateProfile');
        $this->amtgardIdpJwt->expects($this->once())->method('buildAuthorizationJwt')->with($this->userEntity)->willReturn('linked-user-jwt');
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
        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
        $this->orkService->expects($this->once())->method('authorize')->with('testuser', 'testpass')->willReturn(['Token' => 'token-123', 'UserId' => 1001]);
        $this->orkService->expects($this->once())->method('getPlayer')->with('token-123', 1001)->willReturn(['ParkId' => 5, 'username' => 'testuser']);
        $playerData = ['ParkId' => 5, 'username' => 'testuser'];
        $this->orkService->expects($this->once())->method('resolveParkDataFromPlayer')->with($playerData, 123, 'LinkORK')->willReturn(['park_info']);
        $this->orkProfileRepository->expects($this->once())->method('saveOrUpdateProfile')->with($playerData, ['park_info'], 'token-123', 123);
        $this->response->expects($this->once())->method('withHeader')->with('Location', '/resources/profile?success=linked')->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkAccount($this->request, $this->response));
    }

    public function testLinkOrkAccountFailure(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
        $this->orkService->method('authorize')->willReturn(null);
        $this->response->expects($this->once())->method('withHeader')->with('Location', '/resources/profile?error=ork_auth_failed')->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkAccount($this->request, $this->response));
    }

    public function testLinkOrkAccountRedirectsWhenPlayerFetchFails(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
        $this->orkService->method('authorize')->willReturn(['Token' => 'token-123', 'UserId' => 1001]);
        $this->orkService->method('getPlayer')->with('token-123', 1001)->willReturn(null);
        $this->response->expects($this->once())->method('withHeader')->with('Location', '/resources/profile?error=ork_player_failed')->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkAccount($this->request, $this->response));
    }

    public function testStartOrkCodeClaimDoesNotCallAuthorize(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'ork-password']);
        $challenge = new TestMailboxChallengeEntity(testId: 'chal-a', testSentToHash: 'hash');
        $this->mailboxChallenges->expects($this->once())
            ->method('reserve')
            ->with(MailboxChallengePurpose::CLAIM_ORK, '123')
            ->willReturn($challenge);
        $this->orkService->expects($this->never())->method('authorize');
        $this->orkProfileRepository->expects($this->never())->method('saveOrUpdateProfile');
        $this->orkProfileRepository->expects($this->never())->method('linkExistingUserToMundane');
        $this->orkLinkTokenService->method('mintFlowAHandoff')->with('123', 'chal-a')->willReturn('idp-jwt');
        $this->orkLinkTokenService->method('flowAClaimRedirectUrl')->with('idp-jwt', 'testuser')->willReturn('https://ork.example.com/claim');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', 'https://ork.example.com/claim')
            ->willReturnSelf();

        $this->controller->startOrkCodeClaim($this->request, $this->response);
    }

    public function testStartOrkCodeClaimRequiresUsername(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['password' => 'ork-password']);
        $this->orkService->expects($this->never())->method('authorize');
        $this->mailboxChallenges->expects($this->never())->method('reserve');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_username_required')
            ->willReturnSelf();

        $this->controller->startOrkCodeClaim($this->request, $this->response);
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
        $this->orkService->expects($this->never())->method('resolveParkDataFromPlayer');
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

        $playerData = ['ParkId' => 5, 'username' => 'testuser'];
        $this->orkService->expects($this->once())
            ->method('resolveParkDataFromPlayer')
            ->with($playerData, 123, 'RefreshORK')
            ->willReturn(['park_info']);

        $this->orkProfileRepository->expects($this->once())
            ->method('saveOrUpdateProfile')
            ->with($playerData, ['park_info'], 'ork-token-123', 123);

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

        $this->orkService->expects($this->once())
            ->method('resolveParkDataFromPlayer')
            ->with($playerData, 123, 'RefreshORK')
            ->willReturn(null);

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
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('idp_user_id'));

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileWithoutConsumedChallengeIs400(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-user',
            'mundane_id' => 1001,
            'challenge_id' => 'chal-open',
        ]);
        $this->mailboxChallenges->method('isConsumedForUser')->with('chal-open', 'uuid-user')->willReturn(false);
        $this->orkProfileRepository->expects($this->never())->method('linkExistingUserToMundane');
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileRejectsUnknownUser(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-missing',
            'mundane_id' => 1001,
            'challenge_id' => 'chal-1',
        ]);
        $this->mailboxChallenges->method('isConsumedForUser')->willReturn(true);
        $this->userRepository->method('findUserByUserId')->with('uuid-missing')->willReturn(null);
        $this->response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('unknown idp_user_id'));

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileReportsConflict(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-user',
            'mundane_id' => 1001,
            'challenge_id' => 'chal-1',
        ]);
        $this->mailboxChallenges->method('isConsumedForUser')->willReturn(true);
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
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => ' uuid-user ',
            'mundane_id' => '1001',
            'challenge_id' => 'chal-1',
        ]);
        $this->mailboxChallenges->method('isConsumedForUser')->with('chal-1', 'uuid-user')->willReturn(true);
        $this->userRepository->method('findUserByUserId')->with('uuid-user')->willReturn($this->userEntity);
        $this->orkProfileRepository->expects($this->once())
            ->method('linkExistingUserToMundane')
            ->with(123, 1001, 'mirror');
        $this->response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testLinkOrkProfileWithoutChallengeIdKeepsLegacyMirror(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-user',
            'mundane_id' => 1001,
        ]);
        $this->mailboxChallenges->expects($this->never())->method('isConsumedForUser');
        $this->userRepository->method('findUserByUserId')->with('uuid-user')->willReturn($this->userEntity);
        $this->orkProfileRepository->expects($this->once())
            ->method('linkExistingUserToMundane')
            ->with(123, 1001, 'mirror');
        $this->response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->linkOrkProfile($this->request, $this->response));
    }

    public function testCompleteOrkClaimBindsMatchingChallengeWhenEmailsDiffer(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getQueryParams')->willReturn(['t' => 'completion-jwt']);
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => '123',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-complete',
        ]);
        $row = $this->challengeRow();
        $this->mailboxChallenges->method('findById')->with('chal-a')->willReturn($row);
        $this->orkLinkTokenService->method('consumeJti')->with('jti-complete')->willReturn(true);
        $this->orkProfileRepository->expects($this->once())->method('linkExistingUserToMundane')->with(123, 777, 'ork_handoff');
        $this->mailboxChallenges->expects($this->once())->method('consume')->with('chal-a');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=linked')
            ->willReturnSelf();

        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testCompleteOrkClaimRejectsDifferentUserThanChallenge(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getQueryParams')->willReturn(['t' => 'completion-jwt']);
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => 'other-user',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-complete',
        ]);
        $this->orkProfileRepository->expects($this->never())->method('linkExistingUserToMundane');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_complete_failed')
            ->willReturnSelf();

        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testCompleteOrkClaimReplayFailsClosed(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getQueryParams')->willReturn(['t' => 'completion-jwt']);
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => '123',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-replay',
        ]);
        $row = $this->challengeRow();
        $this->mailboxChallenges->method('findById')->willReturn($row);
        $this->orkLinkTokenService->method('consumeJti')->willReturn(false);
        $this->orkProfileRepository->expects($this->never())->method('linkExistingUserToMundane');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?error=ork_complete_replay')
            ->willReturnSelf();

        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testEmailMigrationRoutes(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['new_email' => 'new@example.com', 'code' => '123456']);
        $this->emailMigration->method('start')->willReturn(new MailboxChallengeIssueResult('chal-e', 'hash', true));
        $this->emailMigration->method('confirm')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->emailMigration->method('commit')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));

        $this->controller->startEmailMigration($this->request, $this->response);
        $this->assertSame('chal-e', $_SESSION['email_migration_challenge_id']);
        $this->controller->confirmEmailMigration($this->request, $this->response);
        $this->controller->commitEmailMigration($this->request, $this->response);
    }

    public function testEmailMigrationUnauthenticatedAndInvalid(): void
    {
        $this->controller->startEmailMigration($this->request, $this->response);
        $this->controller->confirmEmailMigration($this->request, $this->response);
        $this->controller->commitEmailMigration($this->request, $this->response);

        $_SESSION['user_id'] = 123;
        $this->request->method('getParsedBody')->willReturn(['new_email' => 'bad']);
        $this->emailMigration->expects($this->once())->method('start')->willThrowException(new \InvalidArgumentException('invalid new email'));
        $this->emailMigration->method('confirm')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::WRONG));
        $this->emailMigration->method('commit')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::UNKNOWN));
        $this->controller->startEmailMigration($this->request, $this->response);
        $this->controller->confirmEmailMigration($this->request, $this->response);
        $this->controller->commitEmailMigration($this->request, $this->response);
        $this->addToAssertionCount(1);
    }

    public function testCompleteOrkClaimUnauthenticated(): void
    {
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/auth/login')
            ->willReturnSelf();
        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testCompleteOrkClaimConflictAndPendingRedirectAndJtiError(): void
    {
        $_SESSION['user_id'] = 123;
        $_SESSION['redirect'] = '/oauth/authorize?client_id=ork-app';
        $this->request->method('getQueryParams')->willReturn(['t' => 'completion-jwt']);
        $row = $this->challengeRow();
        $this->mailboxChallenges->method('findById')->willReturn($row);
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => '123',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-complete',
        ]);
        $this->orkLinkTokenService->method('consumeJti')->willReturn(true);
        $this->orkProfileRepository->method('linkExistingUserToMundane')
            ->willThrowException(new \RuntimeException('conflict: taken'));
        $this->controller->completeOrkClaim($this->request, $this->response);

        $this->orkProfileRepository = $this->createMock(UserOrkProfileRepository::class);
        $this->orkProfileRepository->expects($this->once())->method('linkExistingUserToMundane');
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->willReturn('linked-jwt');
        $this->mailboxChallenges = $this->createMock(MailboxChallengeService::class);
        $this->mailboxChallenges->method('findById')->willReturn($row);
        $this->mailboxChallenges->expects($this->once())->method('consume');
        $this->controller = new ResourcesController(
            $this->logger,
            $this->twig,
            $this->clientRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle,
            $this->database,
            $this->orkService,
            $this->orkProfileRepository,
            $this->userRepository,
            $this->userClientAuthorizationRepository,
            $this->userLoginRepository,
            $this->amtgardIdpJwt,
            $this->userAuthority,
            $this->currentUserResolver,
            ResourcesUserinfoService::builder()->orkProfileRepository($this->orkProfileRepository)->build(),
            $this->clientAccessRepository,
            $this->mailboxChallenges,
            $this->orkLinkTokenService,
            $this->emailMigration,
        );
        $this->controller->completeOrkClaim($this->request, $this->response);
        $this->assertArrayNotHasKey('redirect', $_SESSION);

        $this->orkLinkTokenService = $this->createMock(OrkLinkTokenService::class);
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => '123',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-err',
        ]);
        $this->orkLinkTokenService->method('consumeJti')->willThrowException(new \RuntimeException('db'));
        $this->mailboxChallenges = $this->createMock(MailboxChallengeService::class);
        $this->mailboxChallenges->method('findById')->willReturn($row);
        $this->controller = new ResourcesController(
            $this->logger,
            $this->twig,
            $this->clientRepository,
            $this->redisPubSubQueue,
            $this->pubSubQueueHandle,
            $this->database,
            $this->orkService,
            $this->createMock(UserOrkProfileRepository::class),
            $this->userRepository,
            $this->userClientAuthorizationRepository,
            $this->userLoginRepository,
            $this->amtgardIdpJwt,
            $this->userAuthority,
            $this->currentUserResolver,
            ResourcesUserinfoService::builder()->orkProfileRepository($this->createMock(UserOrkProfileRepository::class))->build(),
            $this->clientAccessRepository,
            $this->mailboxChallenges,
            $this->orkLinkTokenService,
            $this->emailMigration,
        );
        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testCompleteOrkClaimRethrowsNonConflict(): void
    {
        $_SESSION['user_id'] = 123;
        $this->request->method('getQueryParams')->willReturn(['t' => 'completion-jwt']);
        $this->mailboxChallenges->method('findById')->willReturn($this->challengeRow());
        $this->orkLinkTokenService->method('peekFlowACompletion')->willReturn([
            'challenge_id' => 'chal-a',
            'idp_user_id' => '123',
            'mundane_id' => 777,
            'purpose' => 'claim_ork',
            'jti' => 'jti-complete',
        ]);
        $this->orkLinkTokenService->method('consumeJti')->willReturn(true);
        $this->orkProfileRepository->method('linkExistingUserToMundane')
            ->willThrowException(new \RuntimeException('disk full'));
        $this->expectException(\RuntimeException::class);

        $this->controller->completeOrkClaim($this->request, $this->response);
    }

    public function testLinkOrkProfileRethrowsNonConflict(): void
    {
        $this->request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-user',
            'mundane_id' => 1001,
            'challenge_id' => 'chal-1',
        ]);
        $this->mailboxChallenges->method('isConsumedForUser')->willReturn(true);
        $this->userRepository->method('findUserByUserId')->willReturn($this->userEntity);
        $this->orkProfileRepository->method('linkExistingUserToMundane')
            ->willThrowException(new \RuntimeException('disk full'));
        $this->expectException(\RuntimeException::class);
        $this->controller->linkOrkProfile($this->request, $this->response);
    }

    private function challengeRow(): MailboxChallengeEntity
    {
        return new TestMailboxChallengeEntity(testId: 'chal-a');
    }
}
