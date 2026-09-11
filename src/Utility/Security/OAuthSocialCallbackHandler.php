<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Amtgard\IdP\Controllers\Client\AuthorizationFinalizeRedirect;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserLoginEntity;
use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

final class OAuthSocialCallbackHandler
{
    use Builder;
    use Getter;

    protected string $providerName;

    protected LoggerInterface $logger;

    protected string $errorRedirectPath = '/auth/login?policy';

    /** @var callable(array<string, mixed>): mixed */
    protected $fetchToken;

    /** @var callable(mixed): array<string, mixed> */
    protected $mapUserData;

    /**
     * @var callable(array<string, mixed>, AuthorizationFinalizeRedirect): UserEntity
     */
    protected $resolveUser;

    /**
     * @var callable(UserEntity, array<string, mixed>, mixed): UserLoginEntity
     */
    protected $resolveLogin;

    /**
     * @param array<string, mixed> $callbackParams
     * @param callable(UserLoginEntity, AuthorizationFinalizeRedirect): Response $finalizeAuthorization
     */
    public function handle(
        array $callbackParams,
        Response $response,
        callable $finalizeAuthorization,
    ): Response {
        $validationResult = OAuthCallbackValidator::validate($callbackParams, $this->providerName);

        if ($validationResult !== null) {
            $response->getBody()->write($validationResult);

            return $response;
        }

        try {
            $this->logger->info($this->providerName . ' sign-in callback started');

            $token = ($this->fetchToken)($callbackParams);
            $userData = ($this->mapUserData)($token);

            $this->logger->debug($this->providerName . ' user data: ' . json_encode($userData));

            $redirectPolicy = AuthorizationFinalizeRedirect::ReturningUserWithStoredRedirect;
            $resolveUser = $this->resolveUser;
            $user = $resolveUser($userData, $redirectPolicy);
            $login = ($this->resolveLogin)($user, $userData, $token);

            return $finalizeAuthorization($login, $redirectPolicy);
        } catch (\Exception $e) {
            $this->logger->error($this->providerName . ' authentication error: ' . $e->getTraceAsString());

            $response->getBody()->write(
                ScriptAlertResponse::alertAndRedirect($e->getMessage(), $this->errorRedirectPath)
            );

            return $response;
        }
    }
}
