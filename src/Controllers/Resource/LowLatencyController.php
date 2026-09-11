<?php

declare(strict_types=1);


namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class LowLatencyController
{
    public function __construct(
        private RedisCacheRepository $redisCacheRepository,
        private PubSubQueue $redisPubSubQueue,
        private PubSubQueueHandle $pubSubQueueHandle,
        private PvhAuthorizationGate $pvhAuthorizationGate,
        private LoggerInterface $logger,
    ) {
    }

    #[OA\Get(
        path: '/resources/validate',
        operationId: 'validate',
        summary: 'Validate a JWT and get user information',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'jwt',
                in: 'query',
                required: false,
                description: 'Temporary compat: when 1, echo the presented Bearer on 200. Never remints.',
                schema: new OA\Schema(type: 'string', example: '1')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User information response',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        properties: [
                            new OA\Property(property: 'id', type: 'string'),
                            new OA\Property(property: 'email', type: 'string'),
                            new OA\Property(
                                property: 'jwt',
                                type: 'string',
                                description: 'Presented Bearer only when ?jwt=1. Omitted by default. Never a remint.'
                            ),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 409,
                description: 'Presented pvh is one generation behind',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'stale_token'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'unauthorized'),
                    ]
                )
            ),
        ]
    )]
    public function validate(Request $request, Response $response): Response
    {
        $challengeJwt = Jwt::getBearerJwt($request);

        if ($challengeJwt === null) {
            return $this->rejectValidate($response, 'missing_bearer');
        }

        if (Jwt::validateJwtSignature($challengeJwt, $this->logger) === null) {
            return $this->rejectValidate($response, 'invalid_signature');
        }

        $payload = Jwt::parseJwt($challengeJwt);
        if (!is_array($payload)) {
            return $this->rejectValidate($response, 'invalid_payload');
        }

        $tokenUserId = isset($payload['sub']) ? (string) $payload['sub'] : '';
        $aud = isset($payload['aud']) && is_string($payload['aud']) ? $payload['aud'] : '';
        if ($tokenUserId === '' || $aud === '') {
            return $this->rejectValidate($response, 'missing_sub_or_aud', [
                'user_uuid' => $tokenUserId !== '' ? $tokenUserId : null,
                'aud' => $aud !== '' ? $aud : null,
                'client_id' => $aud !== '' ? $aud : null,
            ]);
        }

        if (($payload['iss'] ?? null) !== AuthorizationJwtAssembler::ISSUER) {
            return $this->rejectValidate($response, 'invalid_issuer', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'client_id' => $aud,
            ]);
        }

        $pvhContext = Jwt::presentedPvhContext($payload);
        $presentedPvh = $pvhContext['presented'];
        $fatPolicyHash = $pvhContext['fatPolicyHash'];
        if ($presentedPvh === null && $fatPolicyHash === null) {
            return $this->rejectValidate($response, 'missing_pvh_context', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'client_id' => $aud,
            ]);
        }

        $outcome = $this->pvhAuthorizationGate->evaluateAndSeed($tokenUserId, $aud, $payload);
        $access = $this->pvhAuthorizationGate->lastAccess();
        $cached = $this->pvhAuthorizationGate->lastCachedRecord();

        if ($outcome === PvhGateOutcome::Proceed) {
            $resolved = $this->pvhAuthorizationGate->lastResolvedRecord();
            if ($access === PvhAccess::Current && $resolved !== null) {
                $this->logger->notice(
                    'jwt validate current', [
                    'user_uuid' => $tokenUserId,
                    'aud' => $aud,
                    'client_id' => $aud,
                    'pvh' => $resolved->getPvh(),
                    ]
                );

                return $this->validateSuccess(
                    $request,
                    $response,
                    $challengeJwt,
                    $tokenUserId,
                    $aud,
                    $resolved->getEmail()
                );
            }

            $this->logger->notice(
                'jwt validate cache miss seeded', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'client_id' => $aud,
                'pvh' => $resolved?->getPvh(),
                ]
            );

            return $this->validateSuccess(
                $request,
                $response,
                $challengeJwt,
                $tokenUserId,
                $aud,
                Jwt::emailClaim($payload)
            );
        }

        if ($outcome === PvhGateOutcome::StaleToken) {
            $this->logger->notice(
                'jwt validate stale_token', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'client_id' => $aud,
                'presented_pvh' => $presentedPvh,
                'current_pvh' => $cached?->getPvh(),
                'prev_pvh' => $cached?->getPrevPvh(),
                ]
            );

            return PvhGate::writeStaleToken($response);
        }

        $this->logger->notice(
            'jwt validate unknown pvh', [
            'user_uuid' => $tokenUserId,
            'aud' => $aud,
            'client_id' => $aud,
            'presented_pvh' => $presentedPvh,
            ]
        );

        return PvhGate::writeUnauthorized($response);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function rejectValidate(Response $response, string $reason, array $context = []): Response
    {
        $this->logger->debug('jwt validate rejected', array_merge(['reason' => $reason], $context));

        return PvhGate::writeUnauthorized($response);
    }

    private function validateSuccess(
        Request $request,
        Response $response,
        string $presentedJwt,
        string $userId,
        string $aud,
        string $email
    ): Response {
        $this->redisCacheRepository->queueUserValidation($userId, $aud);

        $handle = $this->pubSubQueueHandle->getHandle();
        $this->redisPubSubQueue->publish($handle, $userId, $email);

        $userData = [
            'id' => $userId,
            'email' => $email,
        ];
        if (($request->getQueryParams()['jwt'] ?? null) === '1') {
            $userData['jwt'] = $presentedJwt;
        }

        return JsonResponseBody::write($response, $userData);
    }
}
