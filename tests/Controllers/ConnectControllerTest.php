<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

require_once __DIR__ . '/AuthControllerTest.php';

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\Client\ConnectController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Services\RegistrationService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

class ConnectControllerTest extends TestCase
{
    private TwigEnvironment $twig;
    private UserRepository $users;
    private UserLoginRepository $logins;
    private UserOrkProfileRepository $orkProfiles;
    private OrkLinkTokenService $tokenService;
    private RegistrationService $registrationService;
    private ConnectController $controller;
    private ResponseInterface $response;
    private StreamInterface $stream;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];
        $_ENV['ORK_BASE_URL'] = 'https://ork.example.com';
        $_ENV['IDP_ORK_SHARED_SECRET'] = str_repeat('s', 32);

        $this->twig = $this->createMock(TwigEnvironment::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->logins = $this->createMock(UserLoginRepository::class);
        $this->orkProfiles = $this->createMock(UserOrkProfileRepository::class);
        $this->tokenService = $this->createMock(OrkLinkTokenService::class);
        $this->registrationService = $this->createMock(RegistrationService::class);

        $this->controller = new ConnectController(
            $this->createMock(EntityManager::class),
            $this->twig,
            $this->users,
            $this->logins,
            $this->orkProfiles,
            $this->tokenService,
            $this->registrationService,
            $this->createMock(LoggerInterface::class),
        );

        $this->stream = $this->createMock(StreamInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();
    }

    public function testShowConnectReturns400WhenTokenMissing(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'invalid')))
            ->willReturn('html');
        $this->stream->expects($this->once())->method('write')->with('html');
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectRendersRegisterTabForNewEmail(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['link_token' => 'jwt-token']);

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'new@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('userExists')->with('new@example.com')->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(function (array $ctx): bool {
                return $ctx['defaultTab'] === 'register'
                    && $ctx['email'] === 'new@example.com'
                    && $ctx['link_token'] === 'jwt-token';
            }))
            ->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectRendersLoginTabForExistingEmail(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['link_token' => 'jwt-token']);

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'existing@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('userExists')->with('existing@example.com')->willReturn(true);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['defaultTab'] === 'login'))
            ->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectReturns400WhenTokenInvalid(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['link_token' => 'bad-jwt']);

        $this->tokenService->method('peekClaims')->willReturn(null);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'expired')))
            ->willReturn('html');
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $this->controller->showConnect($request, $this->response);
    }

    public function testSubmitConnectLoginHandlesConsumeJtiFailure(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'correct',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-user'; }
        };
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);
        $this->tokenService->method('consumeJti')->willThrowException(new \RuntimeException('db error'));

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'still valid')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectLoginReportsLinkConflict(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'correct',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-user'; }
        };
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles->method('linkExistingUserToMundane')
            ->willThrowException(new \RuntimeException('conflict: already linked'));

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'already linked')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectRegisterSurfacesRegistrationError(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]);

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 77,
            'email' => 'new@example.com',
            'jti' => 'jti-2',
        ]);
        $this->registrationService->method('register')->willReturn([
            'ok' => false,
            'error' => 'Email already registered',
        ]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['error'] === 'Email already registered'))
            ->willReturn('html');

        $this->controller->submitConnectRegister($request, $this->response);
    }

    public function testSubmitConnectLoginRejectsBadPassword(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'wrong',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-user'; }
            public function getEmail(): string { return 'user@example.com'; }
        };
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['error'] === 'Email or password incorrect.'))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectLoginSuccessLinksProfileAndRedirects(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'correct',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-user'; }
            public function getEmail(): string { return 'user@example.com'; }
        };
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);
        $this->tokenService->expects($this->once())->method('consumeJti')->with('jti-1')->willReturn(true);
        $this->orkProfiles->expects($this->once())
            ->method('linkExistingUserToMundane')
            ->with(10, 99, 'ork_handoff');

        $this->controller->submitConnectLogin($request, $this->response);

        $this->assertSame('uuid-user', $_SESSION['user_id']);
    }

    public function testSubmitConnectRegisterRejectsPasswordMismatch(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'one',
            'confirmPassword' => 'two',
        ]);

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['error'] === 'Passwords do not match.'))
            ->willReturn('html');

        $this->controller->submitConnectRegister($request, $this->response);
    }

    public function testSubmitConnectRegisterSuccessCreatesUserAndLinks(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'secret',
            'confirmPassword' => 'secret',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 11; }
            public function getUserId(): string { return 'uuid-new'; }
        };
        $login = $this->createMock(UserLoginEntity::class);

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 77,
            'email' => 'new@example.com',
            'jti' => 'jti-2',
        ]);
        $this->registrationService->method('register')->willReturn([
            'ok' => true,
            'user' => $user,
            'login' => $login,
        ]);
        $this->tokenService->expects($this->once())->method('consumeJti')->with('jti-2')->willReturn(true);
        $this->orkProfiles->expects($this->once())
            ->method('linkExistingUserToMundane')
            ->with(11, 77, 'ork_handoff');

        $this->controller->submitConnectRegister($request, $this->response);

        $this->assertSame('uuid-new', $_SESSION['user_id']);
    }

    public function testSubmitConnectRegisterReportsReplayWhenJtiAlreadyConsumed(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'secret',
            'confirmPassword' => 'secret',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
        ]);
        $user = new class extends UserEntity {
            public function getId(): int { return 11; }
            public function getUserId(): string { return 'uuid-new'; }
        };

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 77,
            'email' => 'new@example.com',
            'jti' => 'jti-used',
        ]);
        $this->registrationService->method('register')->willReturn([
            'ok' => true,
            'user' => $user,
            'login' => $this->createMock(UserLoginEntity::class),
        ]);
        $this->tokenService->method('consumeJti')->with('jti-used')->willReturn(false);
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'already been used')))
            ->willReturn('html');

        $this->controller->submitConnectRegister($request, $this->response);
    }

    public function testSubmitConnectRegisterHandlesConsumeJtiFailure(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'secret',
            'confirmPassword' => 'secret',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
        ]);
        $user = new class extends UserEntity {
            public function getId(): int { return 11; }
            public function getUserId(): string { return 'uuid-new'; }
        };

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 77,
            'email' => 'new@example.com',
            'jti' => 'jti-err',
        ]);
        $this->registrationService->method('register')->willReturn([
            'ok' => true,
            'user' => $user,
            'login' => $this->createMock(UserLoginEntity::class),
        ]);
        $this->tokenService->method('consumeJti')->with('jti-err')->willThrowException(new \RuntimeException('db error'));
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'still valid')))
            ->willReturn('html');

        $this->controller->submitConnectRegister($request, $this->response);
    }

    public function testSubmitConnectLoginRedirectsToOrkRootWhenSharedSecretMissing(): void
    {
        unset($_ENV['IDP_ORK_SHARED_SECRET'], $_ENV['ORK_LINK_TOKEN_SECRET']);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'correct',
        ]);

        $user = new class extends UserEntity {
            public function getId(): int { return 10; }
            public function getUserId(): string { return 'uuid-user'; }
            public function getEmail(): string { return 'user@example.com'; }
        };
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles->method('linkExistingUserToMundane');
        $this->response->expects($this->once())->method('withHeader')->with('Location', 'https://ork.example.com/')->willReturnSelf();
        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectLoginReportsReplayWhenJtiAlreadyConsumed(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'correct',
        ]);

        $user = new TestUserEntity('uuid-user', 'user@example.com', 'User');
        $login = new TestUserLoginEntity($user, password_hash('correct', PASSWORD_BCRYPT), 'avatar');

        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'user@example.com',
            'jti' => 'jti-used',
        ]);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn($login);
        $this->tokenService->method('consumeJti')->willReturn(false);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'already been used')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectLoginRejectsInvalidToken(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'bad-jwt',
            'password' => 'secret',
        ]);
        $this->tokenService->method('peekClaims')->willReturn(null);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains($ctx['error'], 'invalid or expired')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }

    public function testSubmitConnectLoginRejectsUnknownUser(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'link_token' => 'jwt-token',
            'password' => 'secret',
        ]);
        $this->tokenService->method('peekClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'missing@example.com',
            'jti' => 'jti-1',
        ]);
        $this->users->method('getUserByEmail')->willReturn(null);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['error'] === 'Email or password incorrect.'))
            ->willReturn('html');

        $this->controller->submitConnectLogin($request, $this->response);
    }
}
