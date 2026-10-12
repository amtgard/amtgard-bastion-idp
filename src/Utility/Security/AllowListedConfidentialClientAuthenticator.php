<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Models\AllowedLinkOrkProfileClientIds;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ServerRequestInterface as Request;
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
        private IdpLogging $logging,
    ) {}

    public function authenticate(Request $request): void
    {
        $credentials = HttpBasicCredentialsParser::fromAuthorizationHeader(
            $request->getHeaderLine('Authorization')
        );

        if (!$credentials->isPresent()) {
            $this->logging->security()->error('allow-listed confidential client auth rejected', [
                'failure_reason' => 'missing_basic',
            ]);
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $basic = $credentials->get();

        if (!$this->allowedClientIds->contains($basic->clientId)) {
            $this->logging->security()->alert('allow-listed confidential client auth rejected', [
                'client_id' => $basic->clientId,
                'failure_reason' => 'client_not_in_allow_list',
            ]);
            throw new HttpUnauthorizedException($request, 'Client not authorized for this endpoint.');
        }

        if (!$this->clientRepository->validateClient(
            $basic->clientId,
            $basic->clientSecret,
            'confidential_basic'
        )) {
            $this->logging->security()->alert('allow-listed confidential client auth rejected', [
                'client_id' => $basic->clientId,
                'failure_reason' => 'invalid_credentials',
            ]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $this->logging->security()->debug('allow-listed confidential client credentials accepted', [
            'client_id' => $basic->clientId,
        ]);
    }
}
