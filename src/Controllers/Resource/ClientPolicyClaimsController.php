<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Utility\JsonResponseBody;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * HTTP edge for a confidential client's IAM policy claims.
 */
final class ClientPolicyClaimsController
{
    public function __construct(
        private LoggerInterface $logger,
        private ClientIamRequestInterpreter $requests,
        private ClientIamPolicyService $iamPolicyService,
    ) {}

    #[OA\Post(
        path: '/resources/client/policy-claims',
        operationId: 'clientAddPolicyClaim',
        summary: 'Add an IAM policy claim for a user (client IAM service scope)',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idp_user_id', 'provisos', 'resource'],
                properties: [
                    new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'provisos', type: 'string', maxLength: 50),
                    new OA\Property(property: 'resource', type: 'string', maxLength: 50),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Claim added (idempotent)'),
            new OA\Response(response: 400, description: 'Invalid request'),
            new OA\Response(response: 404, description: 'Unknown user'),
        ]
    )]
    public function addPolicyClaim(Request $request, Response $response): Response
    {
        $client = $this->requests->client($request);
        $body = (array) $request->getParsedBody();
        $user = $this->requests->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        try {
            $this->iamPolicyService->addClaim(
                $client,
                $user,
                $this->trimmedClaimPart($body['provisos'] ?? null),
                $this->trimmedClaimPart($body['resource'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponseBody::writeError($response, $e->getMessage(), 400);
        }

        $this->logger->info('client iam policy claim added', [
            'client_id' => $client->getIdentifier(),
            'idp_user_id' => $user->getUserId(),
        ]);

        return $response->withStatus(204);
    }

    #[OA\Delete(
        path: '/resources/client/policy-claims',
        operationId: 'clientDeletePolicyClaim',
        summary: 'Delete an IAM policy claim for a user (client IAM service scope)',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idp_user_id', 'provisos', 'resource'],
                properties: [
                    new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'provisos', type: 'string'),
                    new OA\Property(property: 'resource', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Claim deleted'),
            new OA\Response(response: 400, description: 'Invalid request'),
            new OA\Response(response: 404, description: 'Unknown user'),
        ]
    )]
    public function deletePolicyClaim(Request $request, Response $response): Response
    {
        $client = $this->requests->client($request);
        $body = (array) $request->getParsedBody();
        $user = $this->requests->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        try {
            $this->iamPolicyService->deleteClaim(
                $client,
                $user,
                $this->trimmedClaimPart($body['provisos'] ?? null),
                $this->trimmedClaimPart($body['resource'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponseBody::writeError($response, $e->getMessage(), 400);
        }

        $this->logger->info('client iam policy claim deleted', [
            'client_id' => $client->getIdentifier(),
            'idp_user_id' => $user->getUserId(),
        ]);

        return $response->withStatus(204);
    }

    #[OA\Get(
        path: '/resources/client/policy-claims/{idp_user_id}',
        operationId: 'clientListPolicyClaims',
        summary: 'List IAM policy claims for a user within the client IAM service namespace',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Policy claims'),
            new OA\Response(response: 404, description: 'Unknown user'),
        ]
    )]
    public function listPolicyClaims(Request $request, Response $response, string $idpUserId): Response
    {
        $client = $this->requests->client($request);
        $user = $this->requests->requireUser($idpUserId, $response);
        if ($user instanceof Response) {
            return $user;
        }

        return JsonResponseBody::write($response, [
            'claims' => $this->iamPolicyService->listClaims($client, $user),
        ], 200);
    }

    private function trimmedClaimPart(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
