<?php

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\CachedValidatedUserEntity;
use Amtgard\IdP\Utility\Utility;
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

    public function __construct(
        EntityManager $em,
        LoggerInterface $logger,
        ResourceServer $resourceServer,
        AuthorizedClients $validClients
    )
    {
        $this->logger = $logger;
        $this->resourceServer = $resourceServer;
        $this->validClients = $validClients;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (Optional::ofNullable($_SESSION['client_id'])->map(fn($clientId) => in_array($clientId, $this->validClients->getClientIds()))->orElse(false)) {
            return $handler->handle($request);
        }

        $jwt = Optional::ofNullable(Utility::validateJwt($request))->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $payload = Optional::ofNullable(value: Utility::parseJwt($jwt))->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $oauthUserId = Optional::ofNullable($payload['sub'])->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));
        $clientId = Optional::ofNullable($payload['aud'])->orElseThrow(new HttpUnauthorizedException($request, "Not authorized."));

        if (!in_array($clientId, $this->validClients->getClientIds())) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        if ($oauthUserId && $this->redisCacheRepository->isUserInCache($oauthUserId)) {
            $_SESSION['user_id'] = $oauthUserId;
            $_SESSION['client_id'] = $clientId;
            return $handler->handle($request);
        } else {
            $request = $this->resourceServer->validateAuthenticatedRequest($request);
            $_SESSION['user_id'] = $request->getAttribute('oauth_user_id');
            $_SESSION['client_id'] = $clientId;
            $user = Utility::getAuthenticatedUser();
            $this->redisCacheRepository->setUser(CachedValidatedUserEntity::builder()->userId($user->getUserId())->email($user->getEmail())->build());
            return $handler->handle($request);
        }
    }
}