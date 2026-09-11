<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class CachedJwtLocalIdpAuthMiddleware extends LocalIdpAuthMiddleware
{
    public function __construct(
        EntityManager $em,
        protected LoggerInterface $logger,
        private PvhAuthorizationGate $pvhAuthorizationGate,
        protected AuthorizedClients $authorizedClients,
        protected ResourceServer $resourceServer,
        private OAuthAccessTokenFallback $oauthAccessTokenFallback,
    ) {
        parent::__construct($em, $logger, $authorizedClients, $resourceServer);
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
            'cached jwt local idp pvh auth', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'access' => $access?->name,
            ]
        );

        return match ($outcome) {
            PvhGateOutcome::Proceed => $this->proceed($userUuid, $aud, $request, $handler),
            PvhGateOutcome::StaleToken => PvhGate::staleTokenResponse(),
            PvhGateOutcome::Unauthorized => throw new HttpUnauthorizedException($request, 'Not authorized.'),
        };
    }

    private function proceed(
        string $userId,
        string $clientId,
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        LoginSession::setAuthenticatedContext($userId, $clientId);

        return $handler->handle($request);
    }
}
