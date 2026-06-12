<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Middleware\ConfidentialClientBasicAuthMiddleware;
use Amtgard\IdP\Models\AllowedLinkOrkProfileClientIds;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class ConfidentialClientBasicAuthMiddlewareTest extends TestCase
{
    private ClientRepository $clientRepository;
    private AllowedLinkOrkProfileClientIds $allowedClientIds;
    private ConfidentialClientBasicAuthMiddleware $middleware;
    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        $_ENV['LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS'] = 'ork-client,other-client';
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->allowedClientIds = new AllowedLinkOrkProfileClientIds();
        $this->middleware = new ConfidentialClientBasicAuthMiddleware(
            $this->createMock(EntityManager::class),
            $this->clientRepository,
            $this->allowedClientIds,
            $this->createMock(LoggerInterface::class),
        );
        $this->request = $this->createMock(ServerRequestInterface::class);
    }

    public function testProcessRejectsMissingAuthorizationHeader(): void
    {
        $this->request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessRejectsMalformedCredentials(): void
    {
        $this->request->method('getHeaderLine')->willReturn('Basic !!!');

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessRejectsClientNotInAllowList(): void
    {
        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('unknown:secret'));

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessRejectsInvalidCredentialsForAllowListedClient(): void
    {
        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('ork-client:wrong'));
        $this->clientRepository->method('validateClient')->willReturn(false);

        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request, $this->createMock(RequestHandlerInterface::class));
    }

    public function testProcessPassesThroughWhenCredentialsValid(): void
    {
        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('ork-client:secret'));
        $this->clientRepository->expects($this->once())
            ->method('validateClient')
            ->with('ork-client', 'secret', 'confidential_basic')
            ->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->with($this->request)->willReturn($response);

        $this->assertSame($response, $this->middleware->process($this->request, $handler));
    }
}
