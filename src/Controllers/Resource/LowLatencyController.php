<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Models\AuthorizationJwtAssembler;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpUnauthorizedException;

class LowLatencyController
{
    private RedisCacheRepository $redisCacheRepository;
    private PubSubQueue $redisPubSubQueue;
    private PubSubQueueHandle $pubSubQueueHandle;

    public function __construct(
        RedisCacheRepository $redisCacheRepository,
        PubSubQueue $redisPubSubQueue,
        PubSubQueueHandle $pubSubQueueHandle
    ) {
        $this->redisCacheRepository = $redisCacheRepository;
        $this->redisPubSubQueue = $redisPubSubQueue;
        $this->pubSubQueueHandle = $pubSubQueueHandle;
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
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function validate(Request $request, Response $response): Response
    {
        $challengeJwt = Jwt::getBearerJwt($request);

        if (!$challengeJwt || !Jwt::validateJwtSignature($challengeJwt)) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $payload = Jwt::parseJwt($challengeJwt);
        if (!is_array($payload)) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $tokenUserId = isset($payload['sub']) ? (string) $payload['sub'] : '';
        $aud = isset($payload['aud']) && is_string($payload['aud']) ? $payload['aud'] : '';
        if ($tokenUserId === '' || $aud === '') {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        if (($payload['iss'] ?? null) !== AuthorizationJwtAssembler::ISSUER) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $presentedPvh = Jwt::presentedPvhClaim($payload);
        $fatPolicyHash = $presentedPvh === null ? Jwt::policyHashFromFatClaims($payload) : null;
        if ($presentedPvh === null && $fatPolicyHash === null) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $cached = $this->redisCacheRepository->getPvhRecord($tokenUserId, $aud);
        if ($cached !== null) {
            $match = $this->presentedMatchesCache($presentedPvh, $fatPolicyHash, $cached->getPvh());
            if ($match) {
                return $this->validateSuccess(
                    $request,
                    $response,
                    $challengeJwt,
                    $tokenUserId,
                    $aud,
                    $cached->getEmail()
                );
            }

            $prevPvh = $cached->getPrevPvh();
            if ($prevPvh !== null && $this->presentedMatchesCache($presentedPvh, $fatPolicyHash, $prevPvh)) {
                $response->getBody()->write(json_encode(['error' => 'stale_token']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
            }

            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $seedPvh = $presentedPvh ?? Pvh::encode((int) floor(microtime(true) * 1000), $fatPolicyHash);
        $email = isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : '';
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

    private function presentedMatchesCache(?string $presentedPvh, ?string $fatPolicyHash, string $cachedPvh): bool
    {
        if ($presentedPvh !== null) {
            return hash_equals($cachedPvh, $presentedPvh);
        }

        if ($fatPolicyHash === null || !Pvh::isPvhHex($cachedPvh)) {
            return false;
        }

        $presentedPrefix = bin2hex(substr($fatPolicyHash, 0, Pvh::HASH_PREFIX_BYTE_LENGTH));

        return hash_equals(Pvh::hashPrefixHex($cachedPvh), $presentedPrefix);
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
