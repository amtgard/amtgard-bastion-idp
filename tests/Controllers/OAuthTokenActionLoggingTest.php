<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Server\OAuth\OAuthFlowErrorRenderer;
use Amtgard\IdP\Controllers\Server\OAuth\OAuthTokenAction;
use Amtgard\IdP\Utility\Log\IdpLogging;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

final class OAuthTokenActionLoggingTest extends TestCase
{
    public function testOAuthServerExceptionLogsOnOauthChannel(): void
    {
        $testHandler = new TestHandler();
        $oauthLogger = new Logger('oauth');
        $oauthLogger->pushHandler($testHandler);

        $idpLogging = $this->createMock(IdpLogging::class);
        $idpLogging->method('oauth')->willReturn($oauthLogger);

        $server = $this->createMock(AuthorizationServer::class);
        $exception = OAuthServerException::accessDenied('invalid_grant');
        $server->method('respondToAccessTokenRequest')->willThrowException($exception);

        $errorRenderer = OAuthFlowErrorRenderer::builder()
            ->idpLogging($idpLogging)
            ->view($this->createStub(TwigEnvironment::class))
            ->build();

        $action = OAuthTokenAction::builder()
            ->authorizationServer($server)
            ->errorRenderer($errorRenderer)
            ->idpLogging($idpLogging)
            ->build();

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withStatus')->willReturnSelf();
        $response->method('getStatusCode')->willReturn(401);

        $action->handle($request, $response);

        $this->assertTrue($testHandler->hasErrorRecords());
        $this->assertSame('oauth token rejected', $testHandler->getRecords()[0]->message);
        $this->assertSame('token', $testHandler->getRecords()[0]->context['oauth_step']);
    }
}
