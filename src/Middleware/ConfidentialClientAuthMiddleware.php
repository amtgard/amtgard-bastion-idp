<?php

declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
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
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
            $this->logger->info('ConfidentialClientAuth: missing or non-Basic Authorization header');
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new HttpUnauthorizedException($request, 'Malformed credentials.');
        }

        [$clientId, $clientSecret] = explode(':', $decoded, 2);

        if (!$this->clientRepository->validateClient($clientId, $clientSecret, 'confidential_basic')) {
            $this->logger->warning('ConfidentialClientAuth: invalid credentials', ['client_id' => $clientId]);
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        $client = $this->clientRepository->findClientByIdentifier($clientId);
        if (!$client instanceof Client) {
            throw new HttpUnauthorizedException($request, 'Unknown client.');
        }

        if (!$client->getIsConfidential()) {
            throw new HttpUnauthorizedException($request, 'Client endpoints require a confidential client.');
        }

        if ($client->getIamService() === null || $client->getIamService() === '') {
            throw new HttpUnauthorizedException($request, 'Client is not configured with an IAM service namespace.');
        }

        return $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $client));
    }
}
