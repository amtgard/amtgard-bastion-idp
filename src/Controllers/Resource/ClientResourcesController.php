<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Middleware\ConfidentialClientAuthMiddleware;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use Amtgard\IdP\Utility\ClientMetadataValidator;
use Amtgard\IdP\Utility\OrnClaimRegistry;
use OpenApi\Attributes as OA;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class ClientResourcesController
{
    public function __construct(
        private LoggerInterface $logger,
        private ClientResourcesRequestResolver $requestResolver,
        private UserPolicyClaimRepository $policyClaimRepository,
        private UserLoginClientRepository $metadataRepository,
        private RedisCacheRepository $redisCacheRepository,
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
        $client = $this->registeredClient($request);
        $body = (array) $request->getParsedBody();
        $user = $this->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        try {
            OrnClaimRegistry::registerForClient($client);
            $this->policyClaimRepository->addClaim(
                $user->getId(),
                (string) $client->getIamService(),
                $this->trimmedClaimPart($body['provisos'] ?? null),
                $this->trimmedClaimPart($body['resource'] ?? null),
                $user->getId(),
                $client->getId()
            );
            $this->invalidateUserCache($user);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        }

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
        $client = $this->registeredClient($request);
        $body = (array) $request->getParsedBody();
        $user = $this->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        try {
            $this->policyClaimRepository->deleteClaim(
                $user->getId(),
                (string) $client->getIamService(),
                $this->trimmedClaimPart($body['provisos'] ?? null),
                $this->trimmedClaimPart($body['resource'] ?? null)
            );
            $this->invalidateUserCache($user);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        }

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
        $client = $this->registeredClient($request);
        $user = $this->requireUser($idpUserId, $response);
        if ($user instanceof Response) {
            return $user;
        }

        $claims = $this->policyClaimRepository->listClaimsForUser(
            $user->getId(),
            $client->getIamService(),
            $client->getId()
        );

        return $this->json($response, ['claims' => $claims]);
    }

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
        $client = $this->registeredClient($request);
        $body = (array) $request->getParsedBody();
        $context = $this->requireUserAndLogin($body, $response);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $prepared = ClientMetadataValidator::prepare(
                $body['metadata'] ?? null,
                isset($body['encoding']) ? (string) $body['encoding'] : null
            );
            $this->metadataRepository->upsertMetadata(
                $context['user']->getId(),
                $context['loginId'],
                $client->getId(),
                $prepared['payload'],
                $prepared['encoding']
            );
            $this->invalidateUserCache($context['user']);
        } catch (\InvalidArgumentException|\JsonException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
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
        $client = $this->registeredClient($request);
        $context = $this->requireUserAndLoginFromQuery($idpUserId, $request, $response);
        if ($context instanceof Response) {
            return $context;
        }

        $stored = $this->metadataRepository->getMetadata($context['loginId'], $client->getId());

        return Optional::ofNullable($stored)
            ->map(fn (array $metadataRow) => $this->json($response, [
                'login_id' => $context['loginId'],
                'metadata' => $metadataRow['metadata'],
                'encoding' => $metadataRow['encoding'],
            ]))
            ->orElseGet(fn () => $this->jsonError($response, 'metadata not found', 404));
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
        $client = $this->registeredClient($request);
        $context = $this->requireUserAndLoginFromQuery($idpUserId, $request, $response);
        if ($context instanceof Response) {
            return $context;
        }

        $this->metadataRepository->deleteMetadata($context['loginId'], $client->getId());
        $this->invalidateUserCache($context['user']);

        return $response->withStatus(204);
    }

    private function registeredClient(Request $request): Client
    {
        /** @var Client $client */
        $client = $request->getAttribute(ConfidentialClientAuthMiddleware::REQUEST_ATTRIBUTE);
        return $client;
    }

    private function requireUser(mixed $idpUserId, Response $response): UserEntity|Response
    {
        if (!$this->hasNonEmptyPublicId($idpUserId)) {
            return $this->jsonError($response, 'idp_user_id is required', 400);
        }

        return $this->requestResolver->findUserByPublicId($idpUserId)
            ->map(fn (UserEntity $user) => $user)
            ->orElseGet(fn () => $this->jsonError($response, 'unknown idp_user_id', 404));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{user: UserEntity, loginId: int}|Response
     */
    private function requireUserAndLogin(array $body, Response $response): array|Response
    {
        $user = $this->requireUser($body['idp_user_id'] ?? null, $response);
        if ($user instanceof Response) {
            return $user;
        }

        return $this->requireLoginForUser($body['login_id'] ?? null, $user, $response);
    }

    /**
     * @return array{user: UserEntity, loginId: int}|Response
     */
    private function requireUserAndLoginFromQuery(
        string $idpUserId,
        Request $request,
        Response $response
    ): array|Response {
        $user = $this->requireUser($idpUserId, $response);
        if ($user instanceof Response) {
            return $user;
        }

        return $this->requireLoginForUser($request->getQueryParams()['login_id'] ?? null, $user, $response);
    }

    /**
     * @return array{user: UserEntity, loginId: int}|Response
     */
    private function requireLoginForUser(mixed $loginId, UserEntity $user, Response $response): array|Response
    {
        if (!$this->hasPositiveInteger($loginId)) {
            return $this->jsonError($response, 'login_id is required', 400);
        }

        return $this->requestResolver->findLoginIdForUser($loginId, $user->getId())
            ->map(fn (int $resolvedLoginId) => ['user' => $user, 'loginId' => $resolvedLoginId])
            ->orElseGet(fn () => $this->jsonError($response, 'unknown login_id for user', 404));
    }

    private function hasNonEmptyPublicId(mixed $idpUserId): bool
    {
        return Optional::ofNullable(is_string($idpUserId) ? trim($idpUserId) : null)
            ->filter(fn (string $normalized) => $normalized !== '')
            ->isPresent();
    }

    private function hasPositiveInteger(mixed $value): bool
    {
        return Optional::ofNullable(is_numeric($value) ? (int) $value : null)
            ->filter(fn (int $resolved) => $resolved > 0)
            ->isPresent();
    }

    private function trimmedClaimPart(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function invalidateUserCache(UserEntity $user): void
    {
        $this->redisCacheRepository->invalidateUser($user->getUserId());
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }
}
