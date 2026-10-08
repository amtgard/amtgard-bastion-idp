<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

class CachedJwtLocalIdpAuthMiddleware extends LocalIdpAuthMiddleware
{
    public function __construct(
        EntityManager $em,
        protected IdpLogging $idpLogging,
        private PvhAuthorizationGate $pvhAuthorizationGate,
        protected AuthorizedClients $authorizedClients,
        protected ResourceServer $resourceServer,
        private OAuthAccessTokenFallback $oauthAccessTokenFallback,
    ) {
        parent::__construct($em, $idpLogging, $authorizedClients, $resourceServer);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $security = $this->idpLogging->security();
        $jwt = Jwt::validateJwtRequest($request, $security);
        if ($jwt === null) {
            $security->error('cached jwt local idp auth rejected', [
                'failure_reason' => 'jwt_required',
            ]);
            throw new HttpUnauthorizedException($request, 'Authorization JWT required. Obtain one from GET /resources/jwt first.');
        }

        $payload = Jwt::parseJwt($jwt, $security);
        if ($payload === null) {
            $security->error('cached jwt local idp auth rejected', [
                'failure_reason' => 'unparseable_jwt',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $oauthUserId = $payload['sub'] ?? null;
        if ($oauthUserId === null || $oauthUserId === '') {
            $security->error('cached jwt local idp auth rejected', [
                'failure_reason' => 'missing_sub',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $clientId = $payload['aud'] ?? null;
        if ($clientId === null || $clientId === '') {
            $security->error('cached jwt local idp auth rejected', [
                'failure_reason' => 'missing_aud',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        if (!Jwt::isAuthorizationPayload($payload)) {
            $security->debug('cached jwt access token fallback', [
                'aud' => (string) $clientId,
            ]);

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
        RequestLogContext::setIdentity($userUuid, $aud);
        $security->debug(
            'cached jwt local idp pvh auth', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'client_id' => $aud,
            'access' => $access?->name,
            'outcome' => $outcome->name,
            ]
        );

        return match ($outcome) {
            PvhGateOutcome::Proceed => $this->proceed($userUuid, $aud, $request, $handler),
            PvhGateOutcome::StaleToken => $this->rejectStaleToken($userUuid, $aud, $access),
            PvhGateOutcome::Unauthorized => $this->rejectUnauthorized($request, $userUuid, $aud, $access),
        };
    }

    private function rejectStaleToken(string $userUuid, string $aud, ?PvhAccess $access): ResponseInterface
    {
        $this->idpLogging->security()->error('cached jwt local idp pvh rejected', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'access' => $access?->name,
            'outcome' => PvhGateOutcome::StaleToken->name,
        ]);

        return PvhGate::staleTokenResponse();
    }

    private function rejectUnauthorized(
        ServerRequestInterface $request,
        string $userUuid,
        string $aud,
        ?PvhAccess $access,
    ): never {
        $this->idpLogging->security()->error('cached jwt local idp pvh rejected', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'access' => $access?->name ?? PvhAccess::Unknown->name,
            'outcome' => PvhGateOutcome::Unauthorized->name,
        ]);
        throw new HttpUnauthorizedException($request, 'Not authorized.');
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
