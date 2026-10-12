<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

use Psr\Log\LoggerInterface;

/**
 * Shared validation for social OAuth2 provider callbacks.
 */
class OAuthCallbackValidator
{
    public static function validate(
        array $queryParams,
        string $providerName,
        string $redirectUrl = '/auth/login',
        ?LoggerInterface $oauthLogger = null,
    ): ?string {
        $providerError = ScriptAlertResponse::oauthProviderErrorIfPresent($queryParams, $providerName, $redirectUrl);
        if ($providerError !== null) {
            $oauthError = isset($queryParams['error']) ? (string) $queryParams['error'] : 'unknown';
            $oauthLogger?->error('oauth social callback provider error', [
                'provider' => $providerName,
                'oauth_error' => $oauthError,
            ]);

            return $providerError;
        }

        if (!OAuth2StateManager::validateAndConsume($queryParams['state'] ?? null)) {
            $oauthLogger?->error('oauth social callback rejected', [
                'provider' => $providerName,
                'failure_reason' => 'invalid_state',
            ]);

            return ScriptAlertResponse::invalidOAuthState($redirectUrl);
        }

        return null;
    }
}
