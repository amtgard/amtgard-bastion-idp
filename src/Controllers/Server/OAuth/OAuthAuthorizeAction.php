<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Server\OAuth;

use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\OAuth\OAuthUser;
use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Utility\Constants;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Optional\Optional;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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

    protected IdpLogging $idpLogging;

    protected AmtgardIdpJwt $amtgardIdpJwt;

    protected RedisCacheRepository $redisCacheRepository;

    public function handle(Request $request, Response $response): Response
    {
        try {
            $hasStoredRequest = $this->authRequestStore->hasAuthRequest();
            if (!$hasStoredRequest) {
                $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);
                $this->captureNonce($request);
                $this->capturePrompt($request);
                $this->authRequestStore->store($authRequest);
            } else {
                $authRequest = $this->authRequestStore->load();
            }

            $clientId = $authRequest->getClient()->getIdentifier();
            $this->idpLogging->oauth()->debug('oauth authorize auth request loaded', [
                'client_id' => $clientId,
                'has_stored_request' => $hasStoredRequest,
            ]);

            if (!$this->userIsAuthenticated($authRequest)) {
                $sessionUserId = $this->authRequestStore->sessionUserId();
                if ($sessionUserId !== null) {
                    $user = $this->userRepository->getUserEntityById($sessionUserId);
                    if ($user === null) {
                        $this->idpLogging->oauth()->warning('oauth authorize session user missing', [
                            'session_user_id' => $sessionUserId,
                        ]);
                        $this->authRequestStore->clearSessionUserId();

                        return $this->authenticateUser($authRequest, $response);
                    }
                    $authRequest->setUser($user);
                    $this->authRequestStore->store($authRequest);
                } else {
                    return $this->authenticateUser($authRequest, $response);
                }
            }

            if (!$this->clientAuthorizationIsApproved($authRequest)) {
                return $this->requestUserAuthorizationOfClient($authRequest, $response);
            }

            return $this->finalizeAuthorization($authRequest, $response);
        } catch (OAuthServerException $exception) {
            $httpStatus = $exception->getHttpStatusCode();
            $clientId = $this->clientIdFromRequest($request);
            $this->idpLogging->oauth()->error('oauth authorize rejected', [
                'oauth_step' => 'authorize',
                'http_status' => $httpStatus,
                'client_id' => $clientId,
            ]);

            if ($request->getMethod() === 'GET') {
                return $this->errorRenderer->renderOAuthFlowError(
                    $response,
                    self::STEP_AUTHORIZATION,
                    $exception->getMessage(),
                    true,
                    $exception->getHint(),
                    $httpStatus
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

        $nonce = $this->authRequestStore->nonce();
        if ($nonce !== null) {
            $params['nonce'] = $nonce;
        }

        $prompt = $this->authRequestStore->prompt();
        if ($prompt !== null) {
            $params['prompt'] = $prompt;
        }

        return '/oauth/authorize?' . http_build_query($params);
    }

    private function captureNonce(Request $request): void
    {
        $params = $request->getQueryParams();
        if (!array_key_exists('nonce', $params)) {
            return;
        }

        $nonce = $params['nonce'];
        if (!is_string($nonce) || $nonce === '' || strlen($nonce) > 255) {
            throw OAuthServerException::invalidRequest('nonce');
        }

        $this->authRequestStore->storeNonce($nonce);
    }

    private function capturePrompt(Request $request): void
    {
        $params = $request->getQueryParams();
        if (!array_key_exists('prompt', $params)) {
            return;
        }

        $prompt = $params['prompt'];
        if (!is_string($prompt)) {
            throw OAuthServerException::invalidRequest('prompt');
        }

        $values = $this->promptValues($prompt);
        if (in_array('none', $values, true) && count($values) > 1) {
            throw OAuthServerException::invalidRequest('prompt');
        }

        $this->authRequestStore->storePrompt($prompt);
    }

    /**
     * @return list<string>
     */
    private function promptValues(string $prompt): array
    {
        return array_values(array_unique(array_filter(explode(' ', trim($prompt)))));
    }

    private function isSilentPrompt(): bool
    {
        $prompt = $this->authRequestStore->prompt();
        if ($prompt === null) {
            return false;
        }

        return $this->promptValues($prompt) === ['none'];
    }

    private function userIsAuthenticated(AuthorizationRequest $authRequest): bool
    {
        return $this->authRequestStore->sessionUserId() !== null && !is_null($authRequest->getUser());
    }

    private function authenticateUser(AuthorizationRequest $authRequest, Response $response): Response
    {
        if ($this->isSilentPrompt()) {
            return $this->redirectWithOidcError($authRequest, $response, 'login_required');
        }

        $clientId = $authRequest->getClient()->getIdentifier();
        $this->idpLogging->oauth()->info('oauth authorize redirect login', [
            'http_status' => 301,
            'client_id' => $clientId,
        ]);

        $redirectUrl = $this->buildPostAuthenticationRedirectUrl();

        return $response
            ->withStatus(301)
            ->withHeader('Location', '/auth/login?redirect=' . urlencode($redirectUrl));
    }

    private function redirectWithOidcError(
        AuthorizationRequest $authRequest,
        Response $response,
        string $error
    ): Response {
        $redirectUri = (string) $authRequest->getRedirectUri();
        $query = ['error' => $error];
        $state = $authRequest->getState();
        if (is_string($state) && $state !== '') {
            $query['state'] = $state;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $this->authRequestStore->clearAuthorizationState();

        return $response
            ->withStatus(302)
            ->withHeader('Location', $redirectUri . $separator . http_build_query($query));
    }

    private function clientAuthorizationIsApproved(?AuthorizationRequest $authRequest = null): bool
    {
        if ($this->authRequestStore->isApproved()) {
            $this->logApprovalCheck($authRequest, true);

            return true;
        }

        $approved = false;
        if ($authRequest && $authRequest->getUser()) {
            /** @var \Amtgard\IdP\Persistence\Server\Entities\Repository\Client $clientEntity */
            $clientEntity = $this->clientRepository->fetchBy('identifier', $authRequest->getClient()->getIdentifier());
            if ($clientEntity) {
                $approved = $this->userClientAuthorizationRepository->hasAuthorization(
                    $authRequest->getUser()->getIdentifier(),
                    $clientEntity->getId()
                );
            }
        }

        $this->logApprovalCheck($authRequest, $approved);

        return $approved;
    }

    private function logApprovalCheck(?AuthorizationRequest $authRequest, bool $approved): void
    {
        $clientId = $authRequest?->getClient()->getIdentifier();
        $this->idpLogging->oauth()->debug('oauth authorize approval check', [
            'client_id' => $clientId,
            'approved' => $approved,
        ]);
    }

    private function requestUserAuthorizationOfClient(AuthorizationRequest $authRequest, Response $response): Response
    {
        if ($this->isSilentPrompt()) {
            return $this->redirectWithOidcError($authRequest, $response, 'consent_required');
        }

        $sessionUserId = $this->authRequestStore->sessionUserId();
        $authRequest->setUser(
            $this->userRepository->getUserEntityById((string) $sessionUserId)
        );

        $this->authRequestStore->store($authRequest);

        $clientId = $authRequest->getClient()->getIdentifier();
        $scopeCount = count($authRequest->getScopes());
        $this->idpLogging->oauth()->info('oauth authorize redirect approve', [
            'client_id' => $clientId,
            'scope_count' => $scopeCount,
        ]);

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

        $clientId = $authRequest->getClient()->getIdentifier();
        $userUuid = $this->resolveUserUuid($authRequest);
        $this->idpLogging->oauth()->info('oauth authorize completed', [
            'client_id' => $clientId,
            'user_uuid' => $userUuid,
        ]);

        $this->authRequestStore->clearAuthorizationState();

        return $response;
    }

    private function seedPvhAudiences(AuthorizationRequest $authRequest): void
    {
        $user = $this->resolveIdpUser($authRequest);
        $userUuid = $user?->getUserId();
        if ($user === null || !is_string($userUuid) || $userUuid === '') {
            $this->idpLogging->oauth()->warning('oauth pvh seed skipped', [
                'reason' => 'user_unresolved',
            ]);

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
            $this->idpLogging->oauth()->notice('oauth pvh seeded', [
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
        $attached = $oauthUser instanceof OAuthUser ? $oauthUser->attachedUserEntity() : null;

        return Optional::ofNullable($attached)
            ->orElseGet(fn (): ?UserEntity => $this->reloadIdpUser($oauthUser));
    }

    private function reloadIdpUser(?UserEntityInterface $oauthUser): ?UserEntity
    {
        $identifier = $this->authRequestStore->sessionUserId() ?? $oauthUser?->getIdentifier();
        if ($identifier === null || $identifier === '') {
            return null;
        }

        if ($oauthUser instanceof OAuthUser) {
            $this->idpLogging->oauth()->info('oauth user reloaded after session restore', [
                'user_identifier' => $identifier,
            ]);
        }

        $loaded = $this->userRepository->getUserEntityById($identifier);
        if ($loaded instanceof OAuthUser) {
            return $loaded->attachedUserEntity();
        }

        return $loaded instanceof UserEntity ? $loaded : null;
    }

    private function resolveUserUuid(AuthorizationRequest $authRequest): ?string
    {
        $user = $this->resolveIdpUser($authRequest);
        $uuid = $user?->getUserId();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function clientIdFromRequest(Request $request): ?string
    {
        $params = $request->getQueryParams();
        $clientId = $params['client_id'] ?? null;

        return is_string($clientId) && $clientId !== '' ? $clientId : null;
    }
}
