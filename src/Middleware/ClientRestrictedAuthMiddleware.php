<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhGate;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

final class ClientRestrictedAuthMiddleware implements MiddlewareInterface
{
    protected ResourceServer $resourceServer;
    protected LoggerInterface $logger;
    protected AuthorizedClients $validClients;
    private RedisCacheRepository $redisCacheRepository;

    public function __construct(
        EntityManager $em,
        LoggerInterface $logger,
        ResourceServer $resourceServer,
        AuthorizedClients $validClients,
        RedisCacheRepository $redisCacheRepository
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

        if (!Jwt::isAuthorizationPayload($payload)) {
            return $this->authenticateOAuthAccessToken($request, $handler);
        }

        $cached = $this->redisCacheRepository->getPvhRecord((string) $oauthUserId, (string) $clientId);
        $access = PvhGate::evaluate($cached, $payload);

        if ($access === PvhAccess::Current) {
            return $this->proceed((string) $oauthUserId, (string) $clientId, $request, $handler);
        }

        if ($access === PvhAccess::Previous) {
            return PvhGate::staleTokenResponse();
        }

        if ($access === PvhAccess::Miss) {
            $pvhContext = Jwt::presentedPvhContext($payload);
            $this->redisCacheRepository->setPvhRecord(PvhGate::missSeedRecord(
                (string) $oauthUserId,
                (string) $clientId,
                Jwt::emailClaim($payload),
                $pvhContext['presented'],
                $pvhContext['fatPolicyHash']
            ));

            return $this->proceed((string) $oauthUserId, (string) $clientId, $request, $handler);
        }

        throw new HttpUnauthorizedException($request, "Not authorized.");
    }

    private function authenticateOAuthAccessToken(Request $request, RequestHandler $handler): Response
    {
        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($request);
        } catch (OAuthServerException) {
            throw new HttpUnauthorizedException($request, "Not authorized.");
        }

        $userId = (string) $validated->getAttribute('oauth_user_id');
        $clientId = (string) $validated->getAttribute('oauth_client_id');

        return $this->proceed($userId, $clientId, $validated, $handler);
    }

    private function proceed(string $userId, string $clientId, Request $request, RequestHandler $handler): Response
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['client_id'] = $clientId;

        return $handler->handle($request);
    }
}
