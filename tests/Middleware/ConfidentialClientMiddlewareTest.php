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
use Slim\Exception\HttpUnauthorizedException;

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

    public function testAuthMiddlewareReturnsForbiddenWhenIamNamespaceIsMissing(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
            public function getIamService(): ?string { return null; }
        };

        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('validateClient')->willReturn(true);
        $clientRepository->method('findClientByIdentifier')->willReturn($client);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Basic ' . base64_encode('app:secret'));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ConfidentialClientAuthMiddleware(
            new ConfidentialClientAuthenticator($clientRepository, $this->createStub(LoggerInterface::class))
        );
        $result = $middleware->process($request, $handler);

        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $result->getBody(), true);
        $this->assertSame('Client is not configured with an IAM service namespace.', $decoded['error']);
    }

    public function testAuthMiddlewareStillRejectsBadCredentials(): void
    {
        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->method('validateClient')->willReturn(false);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Basic ' . base64_encode('app:wrong'));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $middleware = new ConfidentialClientAuthMiddleware(
            new ConfidentialClientAuthenticator($clientRepository, $this->createStub(LoggerInterface::class))
        );

        $this->expectException(HttpUnauthorizedException::class);
        $middleware->process($request, $handler);
    }
}
