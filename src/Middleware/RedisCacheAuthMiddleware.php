<?php

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\CachedValidatedUserEntity;
use Amtgard\IdP\Utility\Utility;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class RedisCacheAuthMiddleware extends AuthMiddleware
{
    protected LoggerInterface $logger;

    private RedisCacheRepository $redisCacheRepository;
    protected ResourceServer $resourceServer;

    public function __construct(EntityManager $em,
                                LoggerInterface $logger,
                                RedisCacheRepository $redisCacheRepository,
                                ResourceServer $resourceServer) {
        parent::__construct($em, $logger, $resourceServer);
        $this->logger = $logger;
        $this->redisCacheRepository = $redisCacheRepository;
        $this->resourceServer = $resourceServer;
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $oauthUserId = null;

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $jwt = $matches[1];
            $tokenParts = explode('.', $jwt);

            if (count($tokenParts) === 3) {
                $payload = json_decode(base64_decode($tokenParts[1]), true);
                $oauthUserId = $payload['sub'] ?? null;
            }
        }

        if ($oauthUserId && $this->redisCacheRepository->isUserInCache($oauthUserId)) {
            $_SESSION['user_id'] = $oauthUserId;
            return $handler->handle($request);
        } else {
            $request = $this->resourceServer->validateAuthenticatedRequest($request);
            $_SESSION['user_id'] = $request->getAttribute('oauth_user_id');
            $user = Utility::getAuthenticatedUser();
            $this->redisCacheRepository->setUser(CachedValidatedUserEntity::builder()->userId($user->getUserId())->email($user->getEmail())->build());
            return $handler->handle($request);
        }
    }
}
