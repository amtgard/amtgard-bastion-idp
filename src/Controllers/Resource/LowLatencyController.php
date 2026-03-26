<?php

namespace Amtgard\IdP\Controllers\Resource;

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\CachedValidatedUserEntity;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\Utility;
use Amtgard\SetQueue\PubSubQueue;
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

    public function validate(Request $request, Response $response): Response
    {
        /** @var CachedValidatedUserEntity|null $user */
        $user = $this->redisCacheRepository->getUser($_SESSION['user_id'] ?? 0);
        
        if (!$user) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $challengeJwt = Jwt::getBearerJwt($request);

        if (!$challengeJwt || !Jwt::validateJwtSignature($challengeJwt)) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        if (!Jwt::validateJwt($challengeJwt, $user->getJwt())) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $userData = [
            'id' => $user->getUserId(),
            'email' => $user->getEmail(),
            'jwt' => $user->getJwt()
        ];

        $handle = $this->pubSubQueueHandle->getHandle();
        $this->redisPubSubQueue->send($handle, $user->getUserId(), $user->getEmail());

        $response->getBody()->write(json_encode($userData));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
