<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

/**
 * Validates redirect targets to prevent open-redirect vulnerabilities.
 */
class RedirectValidator
{
    /**
     * @psalm-assert-if-true non-empty-string $url
     */
    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return false;
        }

        $requestHost = $_SERVER['HTTP_HOST'] ?? null;
        return $requestHost !== null && strcasecmp($host, $requestHost) === 0;
    }

    /**
     * @psalm-taint-escape url
     * @psalm-flow ($url) -> return
     */
    public static function sanitize(?string $url, string $fallback): string
    {
        if (!self::isSafe($url)) {
            return $fallback;
        }

        return $url;
    }

    /**
     * @psalm-taint-escape url
     * @psalm-flow ($url) -> return
     */
    public static function sanitizeOrNull(?string $url): ?string
    {
        if (!self::isSafe($url)) {
            return null;
        }

        return $url;
    }
}
