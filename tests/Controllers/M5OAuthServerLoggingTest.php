<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthApproveAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthAuthorizeAction;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthSessionAuthRequestStore;
use Amtgard\IdP\Controllers\Server\OAuth2ServerController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Twig\Environment as TwigEnvironment;

final class M5OAuthServerLoggingTest extends TestCase
{
    private function oauthCapture(): object
    {
        $handler = new TestHandler();
        $logger = new Logger('oauth');
        $logger->pushHandler($handler);
        $idpLogging = $this->createMock(IdpLogging::class);
        $idpLogging->method('oauth')->willReturn($logger);

        return (object) ['handler' => $handler, 'idpLogging' => $idpLogging];
    }

    public function testServerControllerLogsClearAuthnAndAuthz(): void
    {
        $capture = $this->oauthCapture();
        @session_start();
        $_SESSION = ['user_id' => 1, 'authRequest' => 'x', 'approved' => true];

        $store = new OAuthSessionAuthRequestStore();
        $controller = new OAuth2ServerController(
            $this->createStub(\Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction::class),
            $this->createStub(OAuthApproveAction::class),
            $this->createStub(OAuthAuthorizeAction::class),
            $store,
            $capture->idpLogging,
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $controller->clearAuthentication($request, $response);
        $controller->clearAuthorizationAndApproval($request, $response);

        $this->assertSame(
            'clear_authn',
            $this->findByMessage($capture->handler, 'oauth session cleared')->context['oauth_step'] ?? null
        );
        $this->assertSame(
            'clear_authz',
            $this->findByMessage($capture->handler, 'oauth authorization state cleared')->context['oauth_step'] ?? null
        );
    }

    public function testAuthorizeActionLogsLoginRedirectOnOauthChannel(): void
    {
        $capture = $this->oauthCapture();
        @session_start();
        $_SESSION = [];

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-a');

        $authRequest = new M5TestAuthorizationRequest($client);
        $server = $this->createMock(AuthorizationServer::class);
        $server->method('validateAuthorizationRequest')->willReturn($authRequest);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();

        $action = OAuthAuthorizeAction::builder()
            ->authorizationServer($server)
            ->clientRepository($this->createStub(ClientRepositoryInterface::class))
            ->userRepository($this->createStub(UserRepositoryInterface::class))
            ->userClientAuthorizationRepository($this->createStub(UserClientAuthorizationRepository::class))
            ->authRequestStore(new OAuthSessionAuthRequestStore())
            ->errorRenderer($this->errorRenderer($capture->idpLogging))
            ->idpLogging($capture->idpLogging)
            ->amtgardIdpJwt($this->createStub(AmtgardIdpJwt::class))
            ->redisCacheRepository($this->createStub(RedisCacheRepository::class))
            ->build();

        $action->handle($request, $response);

        $record = $this->findByMessage($capture->handler, 'oauth authorize redirect login');
        $this->assertSame(Level::Info, $record->level);
        $this->assertSame('client-a', $record->context['client_id'] ?? null);
        $this->assertSame(301, $record->context['http_status'] ?? null);
    }

    public function testAuthorizeActionLogsLeagueRejection(): void
    {
        $capture = $this->oauthCapture();
        @session_start();
        $_SESSION = [];

        $server = $this->createMock(AuthorizationServer::class);
        $server->method('validateAuthorizationRequest')
            ->willThrowException(OAuthServerException::invalidRequest('client_id'));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn(['client_id' => 'bad-client']);

        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();
        $stream->method('write')->willReturn(0);

        $action = OAuthAuthorizeAction::builder()
            ->authorizationServer($server)
            ->clientRepository($this->createStub(ClientRepositoryInterface::class))
            ->userRepository($this->createStub(UserRepositoryInterface::class))
            ->userClientAuthorizationRepository($this->createStub(UserClientAuthorizationRepository::class))
            ->authRequestStore(new OAuthSessionAuthRequestStore())
            ->errorRenderer($this->errorRenderer($capture->idpLogging))
            ->idpLogging($capture->idpLogging)
            ->amtgardIdpJwt($this->createStub(AmtgardIdpJwt::class))
            ->redisCacheRepository($this->createStub(RedisCacheRepository::class))
            ->build();

        $action->handle($request, $response);

        $record = $this->findByMessage($capture->handler, 'oauth authorize rejected');
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame('authorize', $record->context['oauth_step'] ?? null);
        $this->assertSame('bad-client', $record->context['client_id'] ?? null);
    }

    public function testApproveActionLogsDenyAndAllowPaths(): void
    {
        $capture = $this->oauthCapture();
        @session_start();

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('client-1');
        $authRequest = new M5TestAuthorizationRequest($client);
        $_SESSION['authRequest'] = serialize($authRequest);
        $_SESSION['user_id'] = 'user-1';

        $clientRecord = new M5TestClient();
        $clientRecord->setId(99);

        $clientRepository = $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\ClientRepository::class);
        $clientRepository->method('fetchBy')->willReturn($clientRecord);

        $userClientAuth = $this->createMock(UserClientAuthorizationRepository::class);
        $userClientAuth->expects($this->once())->method('authorize')->with('user-1', 99);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturnOnConsecutiveCalls(
            ['action' => 'allow', 'callback' => '/next'],
            ['action' => 'deny'],
        );

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->willReturnSelf();
        $response->method('withHeader')->willReturnSelf();

        $action = OAuthApproveAction::builder()
            ->clientRepository($clientRepository)
            ->userClientAuthorizationRepository($userClientAuth)
            ->authRequestStore(new OAuthSessionAuthRequestStore())
            ->errorRenderer($this->errorRenderer($capture->idpLogging))
            ->view($this->createStub(TwigEnvironment::class))
            ->idpLogging($capture->idpLogging)
            ->build();

        $action->handle($request, $response);

        $allow = $this->findByMessage($capture->handler, 'oauth approve allowed');
        $this->assertSame('allow', $allow->context['action'] ?? null);
        $this->assertSame('user-1', $allow->context['user_uuid'] ?? null);

        $_SESSION = [];
        $request->method('getParsedBody')->willReturn(['action' => 'deny']);
        $action->handle($request, $response);

        $deny = $this->findByMessage($capture->handler, 'oauth approve denied');
        $this->assertSame(Level::Notice, $deny->level);
    }

    public function testFlowErrorRendererUsesErrorForProtocolAndCriticalForInternal(): void
    {
        $capture = $this->oauthCapture();
        $renderer = $this->errorRenderer($capture->idpLogging);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withStatus')->willReturnSelf();
        $stream->method('write')->willReturn(0);

        $renderer->renderOAuthFlowError(
            $response,
            'Authorization request (/oauth/authorize)',
            'bad request',
            true,
            'hint',
            400
        );

        $protocol = $this->findByMessage($capture->handler, 'oauth flow protocol error');
        $this->assertSame(Level::Error, $protocol->level);
        $this->assertTrue($protocol->context['is_protocol_error'] ?? false);

        $renderer->renderOAuthFlowError(
            $response,
            'Authorization request (/oauth/authorize)',
            'fail',
            false,
            null,
            500,
            new \RuntimeException('boom')
        );

        $internal = $this->findByMessage($capture->handler, 'oauth flow internal error');
        $this->assertSame(Level::Critical, $internal->level);
        $this->assertSame(\RuntimeException::class, $internal->context['exception_class'] ?? null);
    }

    private function errorRenderer(IdpLogging $idpLogging): OAuthFlowErrorRenderer
    {
        return OAuthFlowErrorRenderer::builder()
            ->idpLogging($idpLogging)
            ->view($this->createStub(TwigEnvironment::class))
            ->build();
    }

    private function findByMessage(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing log message: ' . $message);
    }
}

/** @internal M5 logging tests */
class M5TestAuthorizationRequest extends \League\OAuth2\Server\RequestTypes\AuthorizationRequest
{
    public function __construct($client = null, $user = null)
    {
        $this->client = $client;
        $this->user = $user;
        $this->scopes = [];
        $this->state = 'test-state';
        $this->redirectUri = 'http://test-redirect';
        $this->codeChallenge = 'test-challenge';
        $this->codeChallengeMethod = 'S256';
    }
}

/** @internal M5 logging tests */
class M5TestClient extends \Amtgard\IdP\Persistence\Server\Entities\Repository\Client
{
    private ?int $testId = null;

    public function setId(int $id): self
    {
        $this->testId = $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->testId;
    }
}
