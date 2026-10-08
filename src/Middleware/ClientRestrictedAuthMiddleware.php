<?php

declare(strict_types=1);


namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\LoginSession;
use Amtgard\IdP\Utility\Jwt;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\Pvh\PvhGateOutcome;
use Amtgard\IdP\Utility\PvhAccess;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\Security\OAuthAccessTokenFallback;
use League\OAuth2\Server\ResourceServer;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpUnauthorizedException;

final class ClientRestrictedAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected IdpLogging $idpLogging,
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
        $sessionClientId = $_SESSION['client_id'] ?? null;
        if (Optional::ofNullable($sessionClientId)->map(fn ($clientId) => in_array($clientId, $this->validClients->getClientIds()))->orElse(false)) {
            $this->idpLogging->security()->debug('client restricted session bypass', [
                'client_id' => (string) $sessionClientId,
            ]);

            return $handler->handle($request);
        }

        $security = $this->idpLogging->security();
        $jwt = Jwt::validateJwtRequest($request, $security);
        if ($jwt === null) {
            $security->error('client restricted auth rejected', [
                'failure_reason' => 'jwt_invalid',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $payload = Jwt::parseJwt($jwt, $security);
        if ($payload === null) {
            $security->error('client restricted auth rejected', [
                'failure_reason' => 'unparseable_jwt',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        if (!Jwt::isAuthorizationPayload($payload)) {
            $security->debug('client restricted access token fallback', [
                'aud' => isset($payload['aud']) ? (string) $payload['aud'] : null,
            ]);

            return $this->oauthAccessTokenFallback->authenticate(
                $request,
                $handler,
                $this->proceed(...)
            );
        }

        $oauthUserId = $payload['sub'] ?? null;
        if ($oauthUserId === null || $oauthUserId === '') {
            $security->error('client restricted auth rejected', [
                'failure_reason' => 'missing_sub',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $clientId = $payload['aud'] ?? null;
        if ($clientId === null || $clientId === '') {
            $security->error('client restricted auth rejected', [
                'failure_reason' => 'missing_aud',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        if (!in_array($clientId, $this->validClients->getClientIds(), true)) {
            $security->error('client restricted auth rejected', [
                'client_id' => (string) $clientId,
                'failure_reason' => 'client_not_restricted',
            ]);
            throw new HttpUnauthorizedException($request, 'Not authorized.');
        }

        $userUuid = (string) $oauthUserId;
        $aud = (string) $clientId;
        $outcome = $this->pvhAuthorizationGate->evaluateAndSeed($userUuid, $aud, $payload);
        $access = $this->pvhAuthorizationGate->lastAccess();
        RequestLogContext::setIdentity($userUuid, $aud);
        $security->debug(
            'client restricted pvh auth', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'client_id' => $aud,
            'access' => $access?->name,
            'outcome' => $outcome->name,
            ]
        );

        return match ($outcome) {
            PvhGateOutcome::Proceed => $this->proceed($userUuid, $aud, $request, $handler),
            PvhGateOutcome::StaleToken => $this->rejectStaleToken($userUuid, $aud),
            PvhGateOutcome::Unauthorized => $this->rejectUnauthorized($request, $userUuid, $aud),
        };
    }

    private function rejectStaleToken(string $userUuid, string $aud): Response
    {
        $this->idpLogging->security()->error('client restricted pvh rejected', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'outcome' => PvhGateOutcome::StaleToken->name,
        ]);

        return PvhGate::staleTokenResponse();
    }

    private function rejectUnauthorized(Request $request, string $userUuid, string $aud): never
    {
        $this->idpLogging->security()->error('client restricted pvh rejected', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'outcome' => PvhGateOutcome::Unauthorized->name,
        ]);
        throw new HttpUnauthorizedException($request, 'Not authorized.');
    }

    private function proceed(string $userId, string $clientId, Request $request, RequestHandler $handler): Response
    {
        LoginSession::setAuthenticatedContext($userId, $clientId);

        return $handler->handle($request);
    }
}
