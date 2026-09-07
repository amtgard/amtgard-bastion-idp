<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class LowLatencyController
{
    private RedisCacheRepository $redisCacheRepository;
    private PubSubQueue $redisPubSubQueue;
    private PubSubQueueHandle $pubSubQueueHandle;
    private LoggerInterface $logger;

    public function __construct(
        RedisCacheRepository $redisCacheRepository,
        PubSubQueue $redisPubSubQueue,
        PubSubQueueHandle $pubSubQueueHandle,
        LoggerInterface $logger
    ) {
        $this->redisCacheRepository = $redisCacheRepository;
        $this->redisPubSubQueue = $redisPubSubQueue;
        $this->pubSubQueueHandle = $pubSubQueueHandle;
        $this->logger = $logger;
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

        if (!$challengeJwt || !Jwt::validateJwtSignature($challengeJwt)) {
            return PvhGate::writeUnauthorized($response);
        }

        $payload = Jwt::parseJwt($challengeJwt);
        if (!is_array($payload)) {
            return PvhGate::writeUnauthorized($response);
        }

        $tokenUserId = isset($payload['sub']) ? (string) $payload['sub'] : '';
        $aud = isset($payload['aud']) && is_string($payload['aud']) ? $payload['aud'] : '';
        if ($tokenUserId === '' || $aud === '') {
            return PvhGate::writeUnauthorized($response);
        }

        if (($payload['iss'] ?? null) !== AuthorizationJwtAssembler::ISSUER) {
            return PvhGate::writeUnauthorized($response);
        }

        $presentedPvh = Jwt::presentedPvhClaim($payload);
        $fatPolicyHash = $presentedPvh === null ? Jwt::policyHashFromFatClaims($payload) : null;
        if ($presentedPvh === null && $fatPolicyHash === null) {
            return PvhGate::writeUnauthorized($response);
        }

        $cached = $this->redisCacheRepository->getPvhRecord($tokenUserId, $aud);
        $access = PvhGate::evaluatePresented($cached, $presentedPvh, $fatPolicyHash);
        if ($access === PvhAccess::Current && $cached !== null) {
            $this->logger->notice('jwt validate current', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'pvh' => $cached->getPvh(),
            ]);
            return $this->validateSuccess(
                $request,
                $response,
                $challengeJwt,
                $tokenUserId,
                $aud,
                $cached->getEmail()
            );
        }
        if ($access === PvhAccess::Previous) {
            $this->logger->notice('jwt validate stale_token', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'presented_pvh' => $presentedPvh,
                'current_pvh' => $cached?->getPvh(),
                'prev_pvh' => $cached?->getPrevPvh(),
            ]);
            return PvhGate::writeStaleToken($response);
        }
        if ($access === PvhAccess::Unknown) {
            $this->logger->notice('jwt validate unknown pvh', [
                'user_uuid' => $tokenUserId,
                'aud' => $aud,
                'presented_pvh' => $presentedPvh,
            ]);
            return PvhGate::writeUnauthorized($response);
        }

        $seedPvh = $presentedPvh ?? Pvh::encode((int) floor(microtime(true) * 1000), $fatPolicyHash);
        $email = isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : '';
        $this->logger->notice('jwt validate cache miss seeded', [
            'user_uuid' => $tokenUserId,
            'aud' => $aud,
            'pvh' => $seedPvh,
        ]);
        $this->redisCacheRepository->setPvhRecord(new PvhCacheRecord(
            $tokenUserId,
            $aud,
            $email,
            $seedPvh,
            null
        ));

        return $this->validateSuccess(
            $request,
            $response,
            $challengeJwt,
            $tokenUserId,
            $aud,
            $email
        );
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

        $response->getBody()->write(json_encode($userData));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
