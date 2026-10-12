<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\LogReaderAuthorizationMiddleware;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Tests\Support\FirebaseJwtTestFactory;
use Amtgard\IdP\Tests\Support\OAuthTestEnvironment;
use Amtgard\IdP\Utility\Pvh;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;

final class LogReaderAuthorizationMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        OAuthTestEnvironment::restorePhpUnitOAuthKeys();
        FirebaseJwtTestFactory::ensureKeys();
    }

    public function testDeniesWhenPolicyMissingLogReaderOrn(): void
    {
        $capture = CapturedIdpLogging::create();
        $middleware = new LogReaderAuthorizationMiddleware($capture->idpLogging);
        $policy = '["Idp:0::::IDP/EditClient"]';
        $pvh = Pvh::encode(1_700_000_000_000, Pvh::policyHash('client-1', $policy, ''));
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient('user-1', 'client-1', $pvh, $policy);

        $request = $this->requestWithRoute('resources.logs.trace', "Bearer {$jwt}");
        $response = $middleware->process($request, $this->noopHandler());

        $this->assertSame(403, $response->getStatusCode());
        $record = $this->findSecurityMessage($capture, 'log reader orn denied');
        $this->assertSame('Idp:0::::IDP/ReadTraceLogs', $record->context['orn'] ?? null);
        $this->assertSame('user-1', $record->context['user_uuid'] ?? null);
    }

    public function testAllowsAnalyzeLogsOnTraceRoute(): void
    {
        $capture = CapturedIdpLogging::create();
        $middleware = new LogReaderAuthorizationMiddleware($capture->idpLogging);
        $policy = '["Idp:0::::IDP/AnalyzeLogs"]';
        $pvh = Pvh::encode(1_700_000_000_000, Pvh::policyHash('client-1', $policy, ''));
        $jwt = FirebaseJwtTestFactory::authorizationForUserAndClient('agent-1', 'client-1', $pvh, $policy);

        $request = $this->requestWithRoute('resources.logs.trace', "Bearer {$jwt}");
        $response = $middleware->process($request, $this->passThroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($capture->handler('security')->hasErrorRecords());
    }

    private function requestWithRoute(string $routeName, string $authorization): ServerRequestInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getName')->willReturn($routeName);
        $routeParser = $this->createMock(RouteParserInterface::class);
        $routingResults = $this->createMock(RoutingResults::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn($authorization);
        $request->method('getAttribute')->willReturnMap([
            [RouteContext::ROUTE, null, $route],
            [RouteContext::ROUTE_PARSER, null, $routeParser],
            [RouteContext::ROUTING_RESULTS, null, $routingResults],
        ]);

        return $request;
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $ok = $this->createMock(ResponseInterface::class);
        $ok->method('getStatusCode')->willReturn(200);
        $handler->method('handle')->willReturn($ok);

        return $handler;
    }

    private function noopHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        return $handler;
    }

    private function findSecurityMessage(CapturedIdpLogging $capture, string $message): \Monolog\LogRecord
    {
        foreach ($capture->handler('security')->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing security log: ' . $message);
    }
}
