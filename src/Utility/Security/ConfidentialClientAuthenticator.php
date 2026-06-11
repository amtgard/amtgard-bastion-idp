<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Optional\Optional;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

final class ConfidentialClientAuthenticator
{
    public function __construct(
        private ClientRepository $clientRepository,
        private LoggerInterface $logger,
    ) {}

    public function authenticate(Request $request, bool $requireIamService): Client
    {
        $credentials = HttpBasicCredentialsParser::fromAuthorizationHeader(
            $request->getHeaderLine('Authorization')
        );

        if (!$credentials->isPresent()) {
            $this->logger->info('ConfidentialClientAuth: missing or non-Basic Authorization header');
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        if (!$this->clientRepository->validateClient(
            $credentials->get()->clientId,
            $credentials->get()->clientSecret,
            'confidential_basic'
        )) {
            $this->logger->warning('ConfidentialClientAuth: invalid credentials', [
                'client_id' => $credentials->get()->clientId,
            ]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $client = Optional::ofNullable(
            $this->clientRepository->findClientByIdentifier($credentials->get()->clientId)
        )->orElseThrow(new HttpUnauthorizedException($request, 'Unknown client.'));

        if (!$client->getIsConfidential()) {
            throw new HttpUnauthorizedException($request, 'Client endpoints require a confidential client.');
        }

        if ($requireIamService) {
            Optional::ofNullable($client->getIamService())
                ->filter(fn (string $iamService) => $iamService !== '')
                ->orElseThrow(new HttpUnauthorizedException(
                    $request,
                    'Client is not configured with an IAM service namespace.'
                ));
        }

        return $client;
    }
}
