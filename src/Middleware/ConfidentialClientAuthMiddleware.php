<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\Security\HttpBasicCredentialsParser;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Server-to-server gate for registered confidential OAuth clients with an
 * assigned IAM service namespace. Sets request attribute `registered_client`.
 */
class ConfidentialClientAuthMiddleware implements MiddlewareInterface
{
    public const REQUEST_ATTRIBUTE = 'registered_client';

    public function __construct(
        EntityManager $entityManager,
        private ClientRepository $clientRepository,
        private LoggerInterface $logger,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $credentials = HttpBasicCredentialsParser::fromAuthorizationHeader(
            $request->getHeaderLine('Authorization')
        );

        if (!$credentials->isPresent()) {
            $this->logger->info('ConfidentialClientAuth: missing or non-Basic Authorization header');
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $client = $this->authenticateClient($request, $credentials->get());

        return $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $client));
    }

    private function authenticateClient(Request $request, \Amtgard\IdP\Utility\Security\HttpBasicCredentials $credentials): Client
    {
        if (!$this->clientRepository->validateClient(
            $credentials->clientId,
            $credentials->clientSecret,
            'confidential_basic'
        )) {
            $this->logger->warning('ConfidentialClientAuth: invalid credentials', [
                'client_id' => $credentials->clientId,
            ]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $client = Optional::ofNullable(
            $this->clientRepository->findClientByIdentifier($credentials->clientId)
        )->orElseThrow(new HttpUnauthorizedException($request, 'Unknown client.'));

        if (!$client->getIsConfidential()) {
            throw new HttpUnauthorizedException($request, 'Client endpoints require a confidential client.');
        }

        // Without a dedicated iam_service namespace, clients cannot scope ORN policy rows.
        Optional::ofNullable($client->getIamService())
            ->filter(fn (string $iamService) => $iamService !== '')
            ->orElseThrow(new HttpUnauthorizedException(
                $request,
                'Client is not configured with an IAM service namespace.'
            ));

        return $client;
    }
}
