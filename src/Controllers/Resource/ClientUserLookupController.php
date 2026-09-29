<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Utility\Client\ClientEmailLookupRejection;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use Amtgard\IdP\Utility\JsonResponseBody;
use OpenApi\Attributes as OA;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Controller for a confidential client's lookup of an IdP user by account email.
 */
final class ClientUserLookupController
{
    public function __construct(
        private LoggerInterface $logger,
        private ClientResourcesRequestResolver $requestResolver,
    ) {}

    #[OA\Get(
        path: '/resources/client/users/by-email',
        operationId: 'clientResolveUserByEmail',
        summary: 'Resolve an IDP public user id (UUID) from an account email',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'email',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'email')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User resolved'),
            new OA\Response(response: 400, description: 'Missing or invalid email'),
            new OA\Response(response: 404, description: 'Unknown email'),
        ]
    )]
    public function resolveUserByEmail(Request $request, Response $response): Response
    {
        $client = $this->registeredClient($request);
        $email = trim((string) ($request->getQueryParams()['email'] ?? ''));

        return Optional::of($email)
            ->filter(static fn (string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $validEmail): Response => $this->respondWithUserForEmail($client, $validEmail, $response))
            ->orElseGet(fn (): Response => $this->rejectEmailLookup(
                $client,
                $response,
                $email === '' ? ClientEmailLookupRejection::Missing : ClientEmailLookupRejection::Invalid
            ));
    }

    private function respondWithUserForEmail(Client $client, string $email, Response $response): Response
    {
        return $this->requestResolver->findUserByEmail($email)
            ->map(fn (UserEntity $user): Response => $this->respondWithResolvedUser($client, $user, $response))
            ->orElseGet(fn (): Response => $this->rejectEmailLookup(
                $client,
                $response,
                ClientEmailLookupRejection::Unknown
            ));
    }

    private function respondWithResolvedUser(Client $client, UserEntity $user, Response $response): Response
    {
        $this->logger->info('client user lookup by email resolved', [
            'client_id' => $client->getIdentifier(),
            'idp_user_id' => $user->getUserId(),
        ]);

        return JsonResponseBody::write($response, [
            'idp_user_id' => $user->getUserId(),
            'email' => $user->getEmail(),
        ], 200);
    }

    private function rejectEmailLookup(
        Client $client,
        Response $response,
        ClientEmailLookupRejection $rejection
    ): Response {
        $this->logger->info('client user lookup by email rejected', [
            'client_id' => $client->getIdentifier(),
            'reason' => $rejection->logReason(),
        ]);

        return JsonResponseBody::writeError($response, $rejection->message(), $rejection->httpStatus());
    }

    private function registeredClient(Request $request): Client
    {
        /** @var Client $client */
        $client = $request->getAttribute(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE);

        return $client;
    }
}
