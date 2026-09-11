<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Utility\Constants;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class OAuthAuthorizeAction
{
    use Builder;
    use Getter;

    private const STEP_AUTHORIZATION = 'Authorization request (/oauth/authorize)';

    protected AuthorizationServer $authorizationServer;

    protected ClientRepositoryInterface $clientRepository;

    protected UserRepositoryInterface $userRepository;

    protected UserClientAuthorizationRepository $userClientAuthorizationRepository;

    protected OAuthSessionAuthRequestStore $authRequestStore;

    protected OAuthFlowErrorRenderer $errorRenderer;

    protected LoggerInterface $logger;

    protected AmtgardIdpJwt $amtgardIdpJwt;

    protected RedisCacheRepository $redisCacheRepository;

    public function handle(Request $request, Response $response): Response
    {
        try {
            if (!$this->authRequestStore->hasAuthRequest()) {
                $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);
                $this->authRequestStore->store($authRequest);
            } else {
                $authRequest = $this->authRequestStore->load();
            }

            if (!$this->userIsAuthenticated($authRequest)) {
                $sessionUserId = $this->authRequestStore->sessionUserId();
                if ($sessionUserId !== null) {
                    $user = $this->userRepository->getUserEntityById($sessionUserId);
                    if ($user === null) {
                        $this->authRequestStore->clearSessionUserId();

                        return $this->authenticateUser($response);
                    }
                    $authRequest->setUser($user);
                    $this->authRequestStore->store($authRequest);
                } else {
                    return $this->authenticateUser($response);
                }
            }

            if (!$this->clientAuthorizationIsApproved($authRequest)) {
                return $this->requestUserAuthorizationOfClient($authRequest, $response);
            }

            return $this->finalizeAuthorization($authRequest, $response);
        } catch (OAuthServerException $exception) {
            $this->logger->error('OAuth authorization server exception', [
                'step' => self::STEP_AUTHORIZATION,
                'message' => $exception->getMessage(),
                'hint' => $exception->getHint(),
            ]);

            if ($request->getMethod() === 'GET') {
                return $this->errorRenderer->renderOAuthFlowError(
                    $response,
                    self::STEP_AUTHORIZATION,
                    $exception->getMessage(),
                    true,
                    $exception->getHint(),
                    $exception->getHttpStatusCode()
                );
            }

            return $exception->generateHttpResponse($response);
        } catch (\Throwable $exception) {
            return $this->errorRenderer->renderOAuthFlowError(
                $response,
                self::STEP_AUTHORIZATION,
                'We could not complete authorization. Please try again or contact an administrator.',
                false,
                null,
                500,
                $exception
            );
        }
    }

    public function buildPostAuthenticationRedirectUrl(): string
    {
        $authRequest = $this->authRequestStore->load();

        $scopes = array_map(function ($scope) {
            return $scope->getIdentifier();
        }, $authRequest->getScopes());

        $params = [
            'scope' => implode(' ', $scopes),
            'state' => $authRequest->getState(),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'redirect_uri' => $authRequest->getRedirectUri(),
            'client_id' => $authRequest->getClient()->getIdentifier(),
            'code_challenge' => $authRequest->getCodeChallenge(),
            'code_challenge_method' => $authRequest->getCodeChallengeMethod(),
        ];

        return '/oauth/authorize?' . http_build_query($params);
    }

    private function userIsAuthenticated(AuthorizationRequest $authRequest): bool
    {
        return $this->authRequestStore->sessionUserId() !== null && !is_null($authRequest->getUser());
    }

    private function authenticateUser(Response $response): Response
    {
        $redirectUrl = $this->buildPostAuthenticationRedirectUrl();

        return $response
            ->withStatus(301)
            ->withHeader('Location', '/auth/login?redirect=' . urlencode($redirectUrl));
    }

    private function clientAuthorizationIsApproved(?AuthorizationRequest $authRequest = null): bool
    {
        if ($this->authRequestStore->isApproved()) {
            return true;
        }

        if ($authRequest && $authRequest->getUser()) {
            /** @var \Amtgard\IdP\Persistence\Server\Entities\Repository\Client $clientEntity */
            $clientEntity = $this->clientRepository->fetchBy('identifier', $authRequest->getClient()->getIdentifier());
            if ($clientEntity) {
                return $this->userClientAuthorizationRepository->hasAuthorization(
                    $authRequest->getUser()->getIdentifier(),
                    $clientEntity->getId()
                );
            }
        }

        return false;
    }

    private function requestUserAuthorizationOfClient(AuthorizationRequest $authRequest, Response $response): Response
    {
        $sessionUserId = $this->authRequestStore->sessionUserId();
        $authRequest->setUser(
            $this->userRepository->getUserEntityById((string) $sessionUserId)
        );

        $this->authRequestStore->store($authRequest);

        return $response
            ->withStatus(301)
            ->withHeader(
                'Location',
                '/oauth/approve?scope=' . urlencode(implode(
                    ',',
                    array_map(
                        fn ($scope) => $scope->getIdentifier(),
                        $authRequest->getScopes()
                    )
                )) . '&callback=/oauth/authorize&client_id=' . $authRequest->getClient()->getIdentifier()
            );
    }

    private function finalizeAuthorization(AuthorizationRequest $authRequest, Response $response): Response
    {
        $this->seedPvhAudiences($authRequest);

        $authRequest->setAuthorizationApproved(true);

        $response = $this->authorizationServer->completeAuthorizationRequest($authRequest, $response);

        $this->authRequestStore->clearAuthorizationState();

        return $response;
    }

    private function seedPvhAudiences(AuthorizationRequest $authRequest): void
    {
        $user = $this->resolveIdpUser($authRequest);
        $userUuid = $user?->getUserId();
        if ($user === null || !is_string($userUuid) || $userUuid === '') {
            $this->logger->warning('oauth pvh seed skipped: user not resolved');

            return;
        }
        $idpAud = Constants::$AMTGARD_IDP_CLIENT_ID;
        $clientAud = $authRequest->getClient()->getIdentifier();
        $legacy = $this->redisCacheRepository->hasLegacyUserEntry($userUuid);

        $audiences = [$idpAud];
        if (is_string($clientAud) && $clientAud !== '' && $clientAud !== $idpAud) {
            $audiences[] = $clientAud;
        }

        foreach ($audiences as $aud) {
            $hasPvh = $this->redisCacheRepository->getPvhRecord($userUuid, $aud) !== null;
            if ($hasPvh && !$legacy) {
                continue;
            }
            $this->amtgardIdpJwt->buildAuthorizationTokens($user, $aud);
            $this->logger->notice('oauth pvh seeded', [
                'user_uuid' => $userUuid,
                'aud' => $aud,
                'legacy' => $legacy,
            ]);
        }

        if ($legacy) {
            $this->redisCacheRepository->deleteLegacyUserEntry($userUuid);
        }
    }

    private function resolveIdpUser(AuthorizationRequest $authRequest): ?UserEntity
    {
        $oauthUser = $authRequest->getUser();
        if ($oauthUser instanceof OAuthUser) {
            return $oauthUser->getUserEntity();
        }

        $identifier = $this->authRequestStore->sessionUserId() ?? $oauthUser?->getIdentifier();
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $loaded = $this->userRepository->getUserEntityById($identifier);
        if ($loaded instanceof OAuthUser) {
            return $loaded->getUserEntity();
        }

        return $loaded instanceof UserEntity ? $loaded : null;
    }
}
