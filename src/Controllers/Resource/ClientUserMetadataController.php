<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Services\ClientIamMetadataService;
use Amtgard\IdP\Utility\JsonResponseBody;
use OpenApi\Attributes as OA;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP edge for a confidential client's per-login JWT metadata.
 */
final class ClientUserMetadataController
{
    public function __construct(
        private ClientIamRequestInterpreter $requests,
        private ClientIamMetadataService $iamMetadataService,
    ) {}

    #[OA\Put(
        path: '/resources/client/user-metadata',
        operationId: 'clientUpsertUserMetadata',
        summary: 'Set per-login metadata embedded in authorization JWTs for this client',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idp_user_id', 'login_id', 'metadata'],
                properties: [
                    new OA\Property(property: 'idp_user_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'login_id', type: 'integer', description: 'IDP user_logins.id for the login method'),
                    new OA\Property(property: 'metadata', description: 'JSON object or base64 string when encoding is base64'),
                    new OA\Property(property: 'encoding', type: 'string', enum: ['json', 'base64'], default: 'json'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Metadata saved'),
            new OA\Response(response: 400, description: 'Invalid metadata'),
            new OA\Response(response: 404, description: 'Unknown user or login'),
        ]
    )]
    public function upsertUserMetadata(Request $request, Response $response): Response
    {
        $client = $this->requests->client($request);
        $body = (array) $request->getParsedBody();
        $context = $this->requests->requireUserAndLogin($body, $response);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->iamMetadataService->upsert(
                $client,
                $context['user'],
                $context['loginId'],
                $body['metadata'] ?? null,
                isset($body['encoding']) ? (string) $body['encoding'] : null,
            );
        } catch (\InvalidArgumentException|\JsonException $e) {
            return JsonResponseBody::writeError($response, $e->getMessage(), 400);
        }

        return $response->withStatus(204);
    }

    #[OA\Get(
        path: '/resources/client/user-metadata/{idp_user_id}',
        operationId: 'clientGetUserMetadata',
        summary: 'Get per-login metadata for this client',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'login_id',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Metadata response'),
            new OA\Response(response: 404, description: 'Unknown user, login, or metadata'),
        ]
    )]
    public function getUserMetadata(Request $request, Response $response, string $idpUserId): Response
    {
        $client = $this->requests->client($request);
        $context = $this->requests->requireUserAndLoginFromQuery($idpUserId, $request, $response);
        if ($context instanceof Response) {
            return $context;
        }

        $stored = $this->iamMetadataService->get($client, $context['user'], $context['loginId']);

        return Optional::ofNullable($stored)
            ->map(fn (array $metadataRow) => JsonResponseBody::write($response, $metadataRow, 200))
            ->orElseGet(fn () => JsonResponseBody::writeError($response, 'metadata not found', 404));
    }

    #[OA\Delete(
        path: '/resources/client/user-metadata/{idp_user_id}',
        operationId: 'clientDeleteUserMetadata',
        summary: 'Remove per-login metadata for this client',
        tags: ['Client'],
        security: [['clientBasicAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'login_id',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Metadata deleted'),
            new OA\Response(response: 404, description: 'Unknown user or login'),
        ]
    )]
    public function deleteUserMetadata(Request $request, Response $response, string $idpUserId): Response
    {
        $client = $this->requests->client($request);
        $context = $this->requests->requireUserAndLoginFromQuery($idpUserId, $request, $response);
        if ($context instanceof Response) {
            return $context;
        }

        $this->iamMetadataService->delete($client, $context['loginId']);

        return $response->withStatus(204);
    }
}
