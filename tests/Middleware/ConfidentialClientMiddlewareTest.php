<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Middleware;

use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Middleware\ConfidentialClientCredentialMiddleware;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ConfidentialClientMiddlewareTest extends TestCase
{
    public function testCredentialMiddlewareSetsRegisteredClientAttribute(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
        };

        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('validateClient')->willReturn(true);
        $clientRepository->method('findClientByIdentifier')->willReturn($client);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Basic ' . base64_encode('app:secret'));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        $middleware = new ConfidentialClientCredentialMiddleware(
            new ConfidentialClientAuthenticator($clientRepository, $this->createMock(LoggerInterface::class))
        );
        $result = $middleware->process($request, $handler);
        $this->assertSame($response, $result);
    }

    public function testAuthMiddlewareRequiresIamService(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
            public function getIamService(): ?string { return 'Skbc'; }
        };

        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('validateClient')->willReturn(true);
        $clientRepository->method('findClientByIdentifier')->willReturn($client);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Basic ' . base64_encode('app:secret'));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        $middleware = new ConfidentialClientAuthMiddleware(
            new ConfidentialClientAuthenticator($clientRepository, $this->createMock(LoggerInterface::class))
        );
        $this->assertSame($response, $middleware->process($request, $handler));
    }
}
