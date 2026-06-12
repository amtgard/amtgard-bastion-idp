<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Security\ConfidentialClientAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class ConfidentialClientAuthenticatorTest extends TestCase
{
    private ClientRepository $clientRepository;
    private ConfidentialClientAuthenticator $authenticator;
    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->authenticator = new ConfidentialClientAuthenticator(
            $this->clientRepository,
            $this->createMock(LoggerInterface::class),
        );
        $this->request = $this->createMock(ServerRequestInterface::class);
    }

    public function testAuthenticateRejectsMissingAuthorizationHeader(): void
    {
        $this->request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $this->expectException(HttpUnauthorizedException::class);
        $this->authenticator->authenticate($this->request, false);
    }

    public function testAuthenticateRejectsInvalidCredentials(): void
    {
        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('app:wrong'));
        $this->clientRepository->method('validateClient')->willReturn(false);

        $this->expectException(HttpUnauthorizedException::class);
        $this->authenticator->authenticate($this->request, false);
    }

    public function testAuthenticateRejectsNonConfidentialClient(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return false; }
        };

        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('app:secret'));
        $this->clientRepository->method('validateClient')->willReturn(true);
        $this->clientRepository->method('findClientByIdentifier')->willReturn($client);

        $this->expectException(HttpUnauthorizedException::class);
        $this->authenticator->authenticate($this->request, false);
    }

    public function testAuthenticateRequiresIamServiceWhenFlagSet(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
            public function getIamService(): ?string { return null; }
        };

        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('app:secret'));
        $this->clientRepository->method('validateClient')->willReturn(true);
        $this->clientRepository->method('findClientByIdentifier')->willReturn($client);

        $this->expectException(HttpUnauthorizedException::class);
        $this->authenticator->authenticate($this->request, true);
    }

    public function testAuthenticateReturnsClientWhenValid(): void
    {
        $client = new class extends Client {
            public function getIsConfidential(): bool { return true; }
            public function getIamService(): ?string { return 'Skbc'; }
        };

        $this->request->method('getHeaderLine')
            ->willReturn('Basic ' . base64_encode('app:secret'));
        $this->clientRepository->method('validateClient')->willReturn(true);
        $this->clientRepository->method('findClientByIdentifier')->willReturn($client);

        $this->assertSame($client, $this->authenticator->authenticate($this->request, true));
    }
}
