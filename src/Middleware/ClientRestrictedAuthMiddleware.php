<?php

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhGate;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class ClientRestrictedAuthMiddleware implements MiddlewareInterface
{
    protected ResourceServer $resourceServer;
    protected LoggerInterface $logger;
    protected AuthorizedClients $validClients;
    private \Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository $redisCacheRepository;

    public function __construct(
        EntityManager $em,
        LoggerInterface $logger,
        ResourceServer $resourceServer,
        AuthorizedClients $validClients,
        \Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository $redisCacheRepository
    )
    {
        $this->logger = $logger;
        $this->resourceServer = $resourceServer;
        $this->validClients = $validClients;
        $this->redisCacheRepository = $redisCacheRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (Optional::ofNullable($_SESSION['client_id'])->map(fn($clientId) => in_array($clientId, $this->validClients->getClientIds()))->orElse(false)) {
            return $handler->handle($request);
        }

        $jwt = Optional::ofNullable(Jwt::validateJwtRequest($request))->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $payload = Optional::ofNullable(value: Jwt::parseJwt($jwt))->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $oauthUserId = Optional::ofNullable($payload['sub'])->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $clientId = Optional::ofNullable($payload['aud'])->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));

        if (!in_array($clientId, $this->validClients->getClientIds())) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $cached = $this->redisCacheRepository->getPvhRecord((string) $oauthUserId, (string) $clientId);
        $access = PvhGate::evaluate($cached, $payload);

        if ($access === PvhAccess::Current) {
            $_SESSION['user_id'] = $oauthUserId;
            $_SESSION['client_id'] = $clientId;
            return $handler->handle($request);
        }

        if ($access === PvhAccess::Previous) {
            return PvhGate::staleTokenResponse();
        }

        throw new HttpUnauthorizedException($request, "Not authorized.");
    }
}
