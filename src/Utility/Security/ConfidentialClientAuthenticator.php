<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Optional\Optional;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpUnauthorizedException;

final class ConfidentialClientAuthenticator
{
    public function __construct(
        private ClientRepository $clientRepository,
        private IdpLogging $logging,
    ) {}

    public function authenticate(Request $request, ConfidentialClientAuthMode $mode): Client
    {
        $credentials = HttpBasicCredentialsParser::fromAuthorizationHeader(
            $request->getHeaderLine('Authorization')
        );

        if (!$credentials->isPresent()) {
            $this->logging->security()->error('confidential client auth rejected', [
                'failure_reason' => 'missing_basic',
            ]);
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $basic = $credentials->get();

        if (!$this->clientRepository->validateClient(
            $basic->clientId,
            $basic->clientSecret,
            'confidential_basic'
        )) {
            $this->logging->security()->error('confidential client auth rejected', [
                'client_id' => $basic->clientId,
                'failure_reason' => 'invalid_credentials',
            ]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $client = $this->clientRepository->findClientByIdentifier($basic->clientId);
        if ($client === null) {
            $this->logging->security()->error('confidential client auth rejected', [
                'client_id' => $basic->clientId,
                'failure_reason' => 'unknown_client',
            ]);
            throw new HttpUnauthorizedException($request, 'Unknown client.');
        }

        if (!$client->getIsConfidential()) {
            $this->logging->security()->error('confidential client auth rejected', [
                'client_id' => $basic->clientId,
                'failure_reason' => 'not_confidential',
            ]);
            throw new HttpUnauthorizedException($request, 'Client endpoints require a confidential client.');
        }

        if ($mode === ConfidentialClientAuthMode::RequireIamService) {
            $namespace = Optional::ofNullable($client->getIamService())
                ->map(fn (string $iamService) => trim($iamService))
                ->filter(fn (string $iamService) => $iamService !== '')
                ->orElse(null);
            if ($namespace === null) {
                $this->logging->security()->error('confidential client auth rejected', [
                    'client_id' => $basic->clientId,
                    'failure_reason' => 'missing_iam_service',
                ]);
                throw new MissingIamServiceNamespace();
            }
        }

        $this->logging->security()->debug('confidential client credentials accepted', [
            'client_id' => $basic->clientId,
        ]);

        return $client;
    }
}
