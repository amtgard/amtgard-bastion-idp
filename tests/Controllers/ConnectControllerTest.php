<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

require_once __DIR__ . '/AuthControllerTest.php';
require_once __DIR__ . '/../Support/ConnectSessionOverride.php';

use Amtgard\IdP\Controllers\Client\ConnectController;
use Amtgard\IdP\Tests\Support\TestMailboxChallengeEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeCheckResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengeIssueResult;
use Amtgard\IdP\Services\Mailbox\MailboxChallengePurpose;
use Amtgard\IdP\Services\MailboxChallengeService;
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
    private MailboxChallengeService $challenges;
    private LoggerInterface $logger;
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
        $this->challenges = $this->createMock(MailboxChallengeService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ConnectController(
            $this->twig,
            $this->users,
            $this->logins,
            $this->orkProfiles,
            $this->tokenService,
            $this->registrationService,
            $this->challenges,
            $this->logger,
        );

        $this->stream = $this->createMock(StreamInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        unset($_ENV['TEST_REGENERATE_FAIL']);
    }

    public function testShowConnectReturns400WhenTokenMissing(): void
    {
        $request = $this->requestWithQuery([]);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'invalid')))
            ->willReturn('html');
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectReturns400WhenTokenInvalid(): void
    {
        $request = $this->requestWithQuery(['link_token' => 'bad-jwt']);
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'expired')))
            ->willReturn('html');
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectLegacyTokenRendersLoginForm(): void
    {
        $request = $this->requestWithQuery(['link_token' => 'legacy-jwt']);
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->tokenService->method('peekLegacyClaims')->willReturn([
            'mundane_id' => 99,
            'email' => 'player@example.com',
            'jti' => 'jti-legacy',
        ]);
        $this->users->method('userExists')->with('player@example.com')->willReturn(true);
        $this->challenges->expects($this->never())->method('issue');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['handoff'] === 'legacy' && $ctx['defaultTab'] === 'login' && $ctx['email'] === 'player@example.com'))
            ->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectMailsCodeAndDoesNotLink(): void
    {
        $request = $this->requestWithQuery(['link_token' => 'jwt-token']);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->challenges->method('findOpenByMundanePurposeAndHash')->willReturn(null);
        $this->challenges->expects($this->once())
            ->method('issue')
            ->with(MailboxChallengePurpose::CLAIM_IDP, 'hint@example.com', null, 99)
            ->willReturn(new MailboxChallengeIssueResult('chal-1', 'hash', true));
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->registrationService->expects($this->never())->method('register');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['challenge_id'] === 'chal-1' && $ctx['needs_password'] === false))
            ->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testShowConnectReusesOpenChallengeWithoutSecondSend(): void
    {
        $request = $this->requestWithQuery(['link_token' => 'jwt-token']);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('findOpenByMundanePurposeAndHash')->willReturn(new TestMailboxChallengeEntity(testId: 'existing-chal'));
        $this->challenges->expects($this->never())->method('issue');
        $this->twig->method('render')->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testPasswordPostWithoutCodeDoesNotLink(): void
    {
        $request = $this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'password' => 'victim-password',
        ]);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::WRONG));
        $this->registrationService->expects($this->never())->method('register');
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectCode($request, $this->response);
    }

    public function testRegisterWithoutSuccessfulCodeCreatesNoUser(): void
    {
        $request = $this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '000000',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::WRONG));
        $this->registrationService->expects($this->never())->method('register');
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectCode($request, $this->response);
    }

    public function testSuccessfulCodeForUnknownAddressAsksForPasswordBeforeRegister(): void
    {
        $request = $this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->registrationService->expects($this->never())->method('register');
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['needs_password'] === true))
            ->willReturn('html');

        $this->controller->submitConnectCode($request, $this->response);
    }

    public function testSuccessfulCodePlusPasswordRegistersAndLinks(): void
    {
        $request = $this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]);
        $user = new TestUserEntity('uuid-new', 'hint@example.com', 'Ada Lovelace');
        $userWithId = new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-new', 'hint@example.com', 'Ada'); }
            public function getId(): int { return 11; }
        };
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->registrationService->expects($this->once())
            ->method('register')
            ->with('Ada', 'Lovelace', 'hint@example.com', 'secret')
            ->willReturn(['ok' => true, 'user' => $userWithId]);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->challenges->expects($this->once())->method('consume')->with('chal-1');
        $this->orkProfiles->expects($this->once())->method('linkExistingUserToMundane')->with(11, 99, 'ork_handoff');
        $this->tokenService->method('hasSharedSecret')->willReturn(true);
        $this->tokenService->method('mintFlowBCompletion')->willReturn('completion-jwt');
        $this->tokenService->method('flowBCompletionRedirectUrl')->willReturn('https://ork.example.com/done');

        $this->controller->submitConnectCode($request, $this->response);
        $this->assertSame('uuid-new', $_SESSION['user_id']);
    }

    public function testSecondBindConflictLeavesTablesUnchangedWhenMundaneTaken(): void
    {
        $request = $this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]);
        $existing = $this->createMock(UserOrkProfileEntity::class);
        $existing->method('getUserId')->willReturn(99);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->orkProfiles->method('findByMundaneId')->willReturn($existing);
        $this->registrationService->expects($this->never())->method('register');
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectCode($request, $this->response);
    }

    public function testReplaySixthWrongAndExpiredFailClosed(): void
    {
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->users->method('getUserByEmail')->willReturn(new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-user', 'hint@example.com', 'User'); }
            public function getId(): int { return 10; }
        });

        foreach ([
            MailboxChallengeCheckResult::LOCKED,
            MailboxChallengeCheckResult::EXPIRED,
            MailboxChallengeCheckResult::CONSUMED,
        ] as $reason) {
            $this->challenges = $this->createMock(MailboxChallengeService::class);
            $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult($reason));
            $controller = new ConnectController(
                $this->twig,
                $this->users,
                $this->createMock(UserLoginRepository::class),
                $this->orkProfiles,
                $this->tokenService,
                $this->registrationService,
                $this->challenges,
                $this->createMock(LoggerInterface::class),
            );
            $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
            $this->twig->method('render')->willReturn('html');
            $controller->submitConnectCode($this->requestWithBody([
                'link_token' => 'jwt-token',
                'challenge_id' => 'chal-1',
                'code' => '123456',
            ]), $this->response);
        }
    }

    public function testExistingUserSuccessLinksWithoutRegister(): void
    {
        $user = new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-user', 'hint@example.com', 'User'); }
            public function getId(): int { return 10; }
        };
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->registrationService->expects($this->never())->method('register');
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles->expects($this->once())->method('linkExistingUserToMundane')->with(10, 99, 'ork_handoff');
        $this->tokenService->method('hasSharedSecret')->willReturn(false);
        $this->tokenService->method('orkBaseUrl')->willReturn('https://ork.example.com');

        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
            'password' => 'ignored',
        ]), $this->response);
    }

    public function testInvalidTokenOnSubmit(): void
    {
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');
        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'bad',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);
    }

    public function testPasswordMismatchAndRegisterError(): void
    {
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
            'password' => 'one',
            'confirmPassword' => 'two',
        ]), $this->response);

        $this->registrationService->expects($this->once())->method('register')->willReturn(['ok' => false, 'error' => 'All fields are required']);
        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]), $this->response);
    }

    public function testReplayJtiAndConsumeFailureAndLinkConflict(): void
    {
        $user = new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-user', 'hint@example.com', 'User'); }
            public function getId(): int { return 10; }
        };
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->twig->method('render')->willReturn('html');

        $this->tokenService->method('consumeJti')->willReturn(false);
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);

        $failing = $this->createMock(OrkLinkTokenService::class);
        $failing->method('peekClaims')->willReturn($this->handoffClaims());
        $failing->method('consumeJti')->willThrowException(new \RuntimeException('db'));
        $controller = new ConnectController(
            $this->twig,
            $this->users,
            $this->createMock(UserLoginRepository::class),
            $this->orkProfiles,
            $failing,
            $this->registrationService,
            $this->challenges,
            $this->createMock(LoggerInterface::class),
        );
        $controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);

        $this->tokenService = $this->createMock(OrkLinkTokenService::class);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles = $this->createMock(UserOrkProfileRepository::class);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->orkProfiles->method('linkExistingUserToMundane')->willThrowException(new \RuntimeException('conflict: taken'));
        $controller = new ConnectController(
            $this->twig,
            $this->users,
            $this->createMock(UserLoginRepository::class),
            $this->orkProfiles,
            $this->tokenService,
            $this->registrationService,
            $this->challenges,
            $this->createMock(LoggerInterface::class),
        );
        $controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);

        $this->orkProfiles = $this->createMock(UserOrkProfileRepository::class);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->orkProfiles->method('linkExistingUserToMundane')->willThrowException(new \RuntimeException('disk full'));
        $controller = new ConnectController(
            $this->twig,
            $this->users,
            $this->createMock(UserLoginRepository::class),
            $this->orkProfiles,
            $this->tokenService,
            $this->registrationService,
            $this->challenges,
            $this->createMock(LoggerInterface::class),
        );
        $controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);
    }

    public function testShowConnectIssuesForExistingUserWithoutLinking(): void
    {
        $user = new TestUserEntity('uuid-user', 'Hint@Example.com', 'User');
        $request = $this->requestWithQuery(['link_token' => 'jwt-token']);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->challenges->method('findOpenByMundanePurposeAndHash')->willReturn(null);
        $this->challenges->expects($this->once())
            ->method('issue')
            ->with(MailboxChallengePurpose::CLAIM_IDP, 'hint@example.com', 'uuid-user', 99)
            ->willReturn(new MailboxChallengeIssueResult('chal-existing', 'hash', true));
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');

        $this->controller->showConnect($request, $this->response);
    }

    public function testLinkWriteThrowableIsGenericFailure(): void
    {
        $user = new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-user', 'hint@example.com', 'User'); }
            public function getId(): int { return 10; }
        };
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles->method('linkExistingUserToMundane')->willThrowException(new \Error('boom'));
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'our end')))
            ->willReturn('html');

        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);
    }

    public function testExistingUserConflictWhenMundaneOwnedByOther(): void
    {
        $user = new class extends TestUserEntity {
            public function __construct() { parent::__construct('uuid-user', 'hint@example.com', 'User'); }
            public function getId(): int { return 10; }
        };
        $existing = $this->createMock(UserOrkProfileEntity::class);
        $existing->method('getUserId')->willReturn(3);
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->orkProfiles->method('findByMundaneId')->willReturn($existing);
        $this->orkProfiles->expects($this->never())->method('linkExistingUserToMundane');
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);
    }

    public function testShowConnectLegacyUnknownEmailDefaultsToRegister(): void
    {
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->users->method('userExists')->with('player@example.com')->willReturn(false);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['defaultTab'] === 'register' && $ctx['handoff'] === 'legacy'))
            ->willReturn('html');

        $this->controller->showConnect($this->requestWithQuery(['link_token' => 'legacy-jwt']), $this->response);
    }

    public function testShowConnectLegacyUsesQueryEmailWhenClaimEmailBlank(): void
    {
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->tokenService->method('peekLegacyClaims')->willReturn(['mundane_id' => 99, 'email' => '', 'jti' => 'jti-legacy']);
        $this->users->method('userExists')->with('query@example.com')->willReturn(true);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['email'] === 'query@example.com'))
            ->willReturn('html');

        $this->controller->showConnect($this->requestWithQuery([
            'link_token' => 'legacy-jwt',
            'email' => 'query@example.com',
        ]), $this->response);
    }

    public function testShowConnectLegacyInvalidTokenRendersError(): void
    {
        $this->tokenService->method('peekClaims')->willReturn(null);
        $this->tokenService->method('peekLegacyClaims')->willReturn(null);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'invalid or expired')))
            ->willReturn('html');

        $this->controller->showConnect($this->requestWithQuery(['link_token' => 'nope']), $this->response);
    }

    public function testSubmitConnectLoginRejectsBadPassword(): void
    {
        $user = $this->linkedUser();
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn(new TestUserLoginEntity($user, password_hash('other', PASSWORD_DEFAULT), ''));
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['handoff'] === 'legacy' && str_contains((string) $ctx['error'], 'incorrect')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'password' => 'secret',
        ]), $this->response);
    }

    public function testSubmitConnectLoginRejectsUnknownAccount(): void
    {
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->twig->expects($this->once())->method('render')->willReturn('html');

        $this->controller->submitConnectLogin($this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'password' => 'secret',
        ]), $this->response);
    }

    public function testSubmitConnectLoginInvalidToken(): void
    {
        $this->tokenService->method('peekLegacyClaims')->willReturn(null);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'invalid or expired')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($this->requestWithBody(['link_token' => 'nope', 'password' => 'x']), $this->response);
    }

    public function testSubmitConnectLoginSuccessRedirectsWithLegacyCompletion(): void
    {
        $this->prepareLegacyLogin();
        $this->tokenService->method('hasSharedSecret')->willReturn(true);
        $this->tokenService->expects($this->once())->method('mintLegacyCompletion')->with('uuid-user', 99)->willReturn('legacy-jwt');
        $this->tokenService->method('flowBCompletionRedirectUrl')->with('legacy-jwt')->willReturn('https://ork.example.com/done');
        $this->response->expects($this->once())->method('withHeader')->with('Location', 'https://ork.example.com/done')->willReturnSelf();

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
        $this->assertSame('uuid-user', $_SESSION['user_id']);
    }

    public function testSubmitConnectLoginStartsSessionWhenInactive(): void
    {
        session_write_close();
        $this->prepareLegacyLogin();
        $this->tokenService->method('hasSharedSecret')->willReturn(false);
        $this->tokenService->method('orkBaseUrl')->willReturn('https://ork.example.com');
        $this->response->expects($this->once())->method('withHeader')->with('Location', 'https://ork.example.com/')->willReturnSelf();

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
        $this->assertSame('uuid-user', $_SESSION['user_id']);
    }

    public function testSubmitConnectLoginWarnsWhenSessionRegenerateFails(): void
    {
        $_ENV['TEST_REGENERATE_FAIL'] = '1';
        $this->prepareLegacyLogin();
        $this->logger->expects($this->once())->method('warning')->with('session_regenerate_id failed during connect handoff', ['user_id' => 'uuid-user']);
        $this->tokenService->method('hasSharedSecret')->willReturn(false);
        $this->tokenService->method('orkBaseUrl')->willReturn('https://ork.example.com');

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
    }

    public function testSubmitConnectLoginConsumeJtiFailure(): void
    {
        $this->prepareLegacyLogin(false);
        $this->tokenService->method('consumeJti')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->once())->method('error');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'still valid')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
    }

    public function testSubmitConnectLoginReplay(): void
    {
        $this->prepareLegacyLogin(false);
        $this->tokenService->method('consumeJti')->willReturn(false);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'already been used')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
    }

    public function testSubmitConnectLoginConflict(): void
    {
        $this->prepareLegacyLogin();
        $this->orkProfiles->method('linkExistingUserToMundane')->willThrowException(new \RuntimeException('conflict: taken'));
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'already linked')))
            ->willReturn('html');

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
    }

    public function testSubmitConnectLoginLinkWriteFailure(): void
    {
        $this->prepareLegacyLogin();
        $this->orkProfiles->method('linkExistingUserToMundane')->willThrowException(new \RuntimeException('disk full'));
        $this->logger->expects($this->once())->method('error');
        $this->twig->method('render')->willReturn('html');

        $this->controller->submitConnectLogin($this->legacyLoginRequest(), $this->response);
    }

    public function testSubmitConnectRegisterPasswordMismatch(): void
    {
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->registrationService->expects($this->never())->method('register');
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => $ctx['defaultTab'] === 'register' && str_contains((string) $ctx['error'], 'do not match')))
            ->willReturn('html');

        $this->controller->submitConnectRegister($this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'password' => 'secret',
            'confirmPassword' => 'other',
        ]), $this->response);
    }

    public function testSubmitConnectRegisterServiceError(): void
    {
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->registrationService->method('register')->willReturn(['ok' => false, 'error' => 'Email already registered.']);
        $this->twig->expects($this->once())
            ->method('render')
            ->with('connect.twig', $this->callback(fn (array $ctx) => str_contains((string) $ctx['error'], 'already registered')))
            ->willReturn('html');

        $this->controller->submitConnectRegister($this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]), $this->response);
    }

    public function testSubmitConnectRegisterSuccess(): void
    {
        $user = $this->linkedUser();
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->registrationService->expects($this->once())
            ->method('register')
            ->with('Ada', 'Lovelace', 'player@example.com', 'secret')
            ->willReturn(['ok' => true, 'user' => $user]);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->orkProfiles->expects($this->once())->method('linkExistingUserToMundane')->with(10, 99, 'ork_handoff');
        $this->tokenService->method('hasSharedSecret')->willReturn(true);
        $this->tokenService->method('mintLegacyCompletion')->willReturn('legacy-jwt');
        $this->tokenService->method('flowBCompletionRedirectUrl')->willReturn('https://ork.example.com/done');

        $this->controller->submitConnectRegister($this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]), $this->response);
        $this->assertSame('uuid-user', $_SESSION['user_id']);
    }

    public function testSubmitConnectRegisterInvalidToken(): void
    {
        $this->tokenService->method('peekLegacyClaims')->willReturn(null);
        $this->twig->expects($this->once())->method('render')->willReturn('html');

        $this->controller->submitConnectRegister($this->requestWithBody([
            'link_token' => 'nope',
            'password' => 'secret',
            'confirmPassword' => 'secret',
        ]), $this->response);
    }

    public function testSubmitConnectCodeStartsSessionAndWarnsOnRegenerateFailure(): void
    {
        session_write_close();
        $_ENV['TEST_REGENERATE_FAIL'] = '1';
        $user = $this->linkedUser();
        $this->tokenService->method('peekClaims')->willReturn($this->handoffClaims());
        $this->challenges->method('check')->willReturn(new MailboxChallengeCheckResult(MailboxChallengeCheckResult::OK));
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->orkProfiles->method('findByMundaneId')->willReturn(null);
        $this->tokenService->method('consumeJti')->willReturn(true);
        $this->challenges->expects($this->once())->method('consume');
        $this->logger->expects($this->once())->method('warning');
        $this->tokenService->method('hasSharedSecret')->willReturn(false);
        $this->tokenService->method('orkBaseUrl')->willReturn('https://ork.example.com');

        $this->controller->submitConnectCode($this->requestWithBody([
            'link_token' => 'jwt-token',
            'challenge_id' => 'chal-1',
            'code' => '123456',
        ]), $this->response);
        $this->assertSame('uuid-user', $_SESSION['user_id']);
    }

    /**
     * @return array{mundane_id: int, idp_email: string, challenge_id: string, jti: string}
     */
    private function handoffClaims(): array
    {
        return [
            'mundane_id' => 99,
            'idp_email' => 'hint@example.com',
            'challenge_id' => 'ork-chal',
            'jti' => 'jti-1',
        ];
    }

    private function requestWithQuery(array $query): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWithBody(array $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    /**
     * @return array{mundane_id: int, email: string, jti: string}
     */
    private function legacyClaims(): array
    {
        return [
            'mundane_id' => 99,
            'email' => 'player@example.com',
            'jti' => 'jti-legacy',
        ];
    }

    private function linkedUser(): TestUserEntity
    {
        return new class extends TestUserEntity {
            public function __construct()
            {
                parent::__construct('uuid-user', 'player@example.com', 'Player');
            }

            public function getId(): int
            {
                return 10;
            }
        };
    }

    private function prepareLegacyLogin(bool $consume = true): void
    {
        $user = $this->linkedUser();
        $this->tokenService->method('peekLegacyClaims')->willReturn($this->legacyClaims());
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByUser')->willReturn(new TestUserLoginEntity($user, password_hash('secret', PASSWORD_DEFAULT), ''));
        if ($consume) {
            $this->tokenService->method('consumeJti')->willReturn(true);
        }
    }

    private function legacyLoginRequest(): ServerRequestInterface
    {
        return $this->requestWithBody([
            'link_token' => 'legacy-jwt',
            'password' => 'secret',
        ]);
    }
}
