<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
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
    public function __construct(
        EntityManager $em,
        protected LoggerInterface $logger,
        protected ResourceServer $resourceServer,
        protected AuthorizedClients $validClients,
        private PvhAuthorizationGate $pvhAuthorizationGate,
        private OAuthAccessTokenFallback $oauthAccessTokenFallback,
    ) {
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
            return $this->oauthAccessTokenFallback->authenticate(
                $request,
                $handler,
                $this->proceed(...)
            );
        }

        $userUuid = (string) $oauthUserId;
        $aud = (string) $clientId;
        $outcome = $this->pvhAuthorizationGate->evaluateAndSeed($userUuid, $aud, $payload);
        $access = $this->pvhAuthorizationGate->lastAccess();
        $this->logger->debug(
            'client restricted pvh auth', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'access' => $access?->name,
            ]
        );

        return match ($outcome) {
            PvhGateOutcome::Proceed => $this->proceed($userUuid, $aud, $request, $handler),
            PvhGateOutcome::StaleToken => PvhGate::staleTokenResponse(),
            PvhGateOutcome::Unauthorized => throw new HttpUnauthorizedException($request, "Not authorized."),
        };
    }

    private function proceed(string $userId, string $clientId, Request $request, RequestHandler $handler): Response
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['client_id'] = $clientId;

        return $handler->handle($request);
    }
}
