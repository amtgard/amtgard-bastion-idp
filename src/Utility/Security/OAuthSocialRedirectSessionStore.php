<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

final class OAuthSocialRedirectSessionStore
{
    /**
     * @param array<string, mixed> $queryParams
     */
    public static function storeFromQueryParams(array $queryParams): void
    {
        $_SESSION['redirect'] = RedirectValidator::sanitizeOrNull($queryParams['redirect'] ?? null);
        $_SESSION['jwtpublickey'] = $queryParams['jwtpublickey'] ?? null;
    }
}
