<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Models\AllowedLinkOrkProfileClientIds;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

/**
 * HTTP Basic confidential client check with an env-driven client_id allow-list.
 *
 * Used by server-to-server endpoints (e.g. link-ork-profile) that must not accept
 * every registered confidential client.
 */
final class AllowListedConfidentialClientAuthenticator
{
    public function __construct(
        private ClientRepository $clientRepository,
        private AllowedLinkOrkProfileClientIds $allowedClientIds,
        private LoggerInterface $logger,
    ) {}

    public function authenticate(Request $request): void
    {
        $credentials = HttpBasicCredentialsParser::fromAuthorizationHeader(
            $request->getHeaderLine('Authorization')
        );

        if (!$credentials->isPresent()) {
            $this->logger->info('ConfidentialClientBasic: missing or non-Basic Authorization header');
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $basic = $credentials->get();

        if (!$this->allowedClientIds->contains($basic->clientId)) {
            $this->logger->warning('ConfidentialClientBasic: client_id not in allow-list', [
                'client_id' => $basic->clientId,
            ]);
            throw new HttpUnauthorizedException($request, 'Client not authorized for this endpoint.');
        }

        if (!$this->clientRepository->validateClient(
            $basic->clientId,
            $basic->clientSecret,
            'confidential_basic'
        )) {
            $this->logger->warning('ConfidentialClientBasic: bad credentials for allow-listed client', [
                'client_id' => $basic->clientId,
            ]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $this->logger->debug('ConfidentialClientBasic: credentials accepted', [
            'client_id' => $basic->clientId,
        ]);
    }
}
