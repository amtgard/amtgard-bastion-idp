<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Client\AuthController;
use Amtgard\IdP\Controllers\Client\ConnectController;
use Amtgard\IdP\Controllers\Client\GoogleAuthController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Services\RegistrationService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Security\OAuthCallbackValidator;
use Amtgard\IdP\Utility\Security\OAuthSocialCallbackHandler;
use League\OAuth2\Client\Provider\Google;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

final class M6ClientIdentityLoggingTest extends TestCase
{
    public function testAuthControllerLogsInvalidCredentialsOnSecurityChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $users = $this->createMock(UserRepository::class);
        $users->method('getUserByEmail')->willReturn(null);

        $controller = new AuthController(
            $users,
            $this->createMock(UserLoginRepository::class),
            $this->createMock(LoggerInterface::class),
            $capture->idpLogging,
            $this->createMock(Google::class),
            $this->createMock(\League\OAuth2\Client\Provider\Facebook::class),
            $this->createMock(\Wohali\OAuth2\Client\Provider\Discord::class),
            $this->createMock(AmtgardIdpJwt::class),
            $this->createMock(TwigEnvironment::class),
            $this->createMock(RedisCacheRepository::class),
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['email' => 'a@b.com', 'password' => 'x']);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('write');

        $controller->login($request, $response);

        $record = $this->findByMessage($capture->handler('security'), 'local login rejected');
        $this->assertSame('invalid_credentials', $record->context['failure_reason'] ?? null);
        $this->assertSame(hash('sha256', 'a@b.com'), $record->context['email_sha256'] ?? null);
    }

    public function testRegistrationServiceLogsEmailExistsRule(): void
    {
        $capture = CapturedIdpLogging::create();
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(true);

        $service = new RegistrationService(
            $users,
            $this->createMock(UserLoginRepository::class),
            $capture->idpLogging,
        );

        $result = $service->register('A', 'B', 'dup@example.com', 'secret123');

        $this->assertFalse($result['ok']);
        $record = $this->findByMessage($capture->handler('security'), 'registration rejected');
        $this->assertSame('email_exists', $record->context['rule'] ?? null);
        $this->assertSame(hash('sha256', 'dup@example.com'), $record->context['email_sha256'] ?? null);
    }

    public function testOAuthCallbackValidatorLogsProviderErrorOnOauthChannel(): void
    {
        $handler = new TestHandler();
        $oauth = new \Monolog\Logger('oauth');
        $oauth->pushHandler($handler);

        OAuthCallbackValidator::validate(
            ['error' => 'access_denied'],
            'Google',
            '/auth/login',
            $oauth,
        );

        $record = $this->findByMessage($handler, 'oauth social callback provider error');
        $this->assertSame('Google', $record->context['provider'] ?? null);
        $this->assertSame('access_denied', $record->context['oauth_error'] ?? null);
    }

    public function testOAuthSocialHandlerLogsCallbackValidationFailure(): void
    {
        $capture = CapturedIdpLogging::create();
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->expects($this->once())->method('write');

        $handler = OAuthSocialCallbackHandler::builder()
            ->providerName('Google')
            ->logger($this->createMock(LoggerInterface::class))
            ->idpLogging($capture->idpLogging)
            ->fetchToken(fn () => null)
            ->mapUserData(fn () => [])
            ->resolveUser(fn () => throw new \RuntimeException('unused'))
            ->resolveLogin(fn () => throw new \RuntimeException('unused'))
            ->build();

        $handler->handle(['state' => 'bad'], $response, fn () => $response);

        $records = array_filter(
            $capture->handler('oauth')->getRecords(),
            static fn (LogRecord $r) => $r->message === 'oauth social callback rejected'
                && ($r->context['failure_reason'] ?? null) === 'callback_validation',
        );
        $this->assertCount(1, $records);
    }

    public function testGoogleRedirectLogsOauthChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $provider = $this->createMock(Google::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://google.example/auth');
        $provider->method('getState')->willReturn('state');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withStatus')->willReturnSelf();

        $controller = new GoogleAuthController(
            $this->createMock(UserRepository::class),
            $this->createMock(UserLoginRepository::class),
            $this->createMock(LoggerInterface::class),
            $capture->idpLogging,
            $this->createMock(AmtgardIdpJwt::class),
            $provider,
        );

        $controller->redirectToGoogle($request, $response);

        $record = $this->findByMessage($capture->handler('oauth'), 'oauth social redirect');
        $this->assertSame('Google', $record->context['provider'] ?? null);
    }

    public function testConnectShowConnectLogsMissingLinkToken(): void
    {
        $capture = CapturedIdpLogging::create();
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->method('render')->willReturn('html');

        $controller = new ConnectController(
            $twig,
            $this->createMock(UserRepository::class),
            $this->createMock(UserLoginRepository::class),
            $this->createMock(UserOrkProfileRepository::class),
            $this->createMock(OrkLinkTokenService::class),
            $this->createMock(RegistrationService::class),
            $this->createMock(MailboxChallengeService::class),
            $this->createMock(LoggerInterface::class),
            $capture->idpLogging,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withStatus')->willReturnSelf();
        $stream->method('write');

        $controller->showConnect($request, $response);

        $record = $this->findByMessage($capture->handler('security'), 'connect handoff rejected');
        $this->assertSame('missing_link_token', $record->context['reason'] ?? null);
    }

    private function findByMessage(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Expected log message not found: ' . $message);
    }
}
