<?php

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhGate;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class CachedJwtLocalIdpAuthMiddleware extends LocalIdpAuthMiddleware
{
    protected LoggerInterface $logger;
    private RedisCacheRepository $redisCacheRepository;
    protected ResourceServer $resourceServer;
    protected AuthorizedClients $authorizedClients;

    public function __construct(
        EntityManager $em,
        LoggerInterface $logger,
        RedisCacheRepository $redisCacheRepository,
        AuthorizedClients $authorizedClients,
        ResourceServer $resourceServer
    ) {
        parent::__construct($em, $logger, $authorizedClients, $resourceServer);
        $this->logger = $logger;
        $this->redisCacheRepository = $redisCacheRepository;
        $this->authorizedClients = $authorizedClients;
        $this->resourceServer = $resourceServer;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $jwt = Optional::ofNullable(Jwt::validateJwtRequest($request))
            ->orElseThrow(new HttpUnauthorizedException($request, 'Authorization JWT required. Obtain one from GET /resources/jwt first.'));

        $payload = Optional::ofNullable(Jwt::parseJwt($jwt))
            ->orElseThrow(new HttpUnauthorizedException($request, 'Not authorized.'));
        $oauthUserId = Optional::ofNullable($payload['sub'] ?? null)
            ->orElseThrow(new HttpUnauthorizedException($request, 'Not authorized.'));
        $clientId = Optional::ofNullable($payload['aud'] ?? null)
            ->orElseThrow(new HttpUnauthorizedException($request, 'Not authorized.'));

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

        throw new HttpUnauthorizedException($request, 'Not authorized.');
    }
}
