<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Ork;

/**
 * ORK JSON often returns asset URLs using the server-side HTTP Host (e.g. Docker
 * service name). Rewrite them to the browser-facing ORK origin from ORK_BASE_URL.
 */
final class OrkPublicAssetUrl
{
    /** @var list<string> */
    private const INTERNAL_HOSTS = [
        'ork3-php8-app',
        'amtgard-idp',
    ];

    public static function forBrowser(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $origin = self::orkPublicOrigin();
        if ($origin === '') {
            return self::fixDuplicateQueryMarkers($url);
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return self::fixDuplicateQueryMarkers($url);
        }

        $path = $parts['path'] ?? '';
        if ($path === '') {
            return self::fixDuplicateQueryMarkers($url);
        }

        $needsRewrite = in_array(strtolower((string) ($parts['host'] ?? '')), self::INTERNAL_HOSTS, true)
            || str_starts_with($path, '/assets/');

        if (!$needsRewrite) {
            return self::fixDuplicateQueryMarkers($url);
        }

        $rewritten = $origin . $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rewritten .= '?' . $parts['query'];
        }

        return self::fixDuplicateQueryMarkers($rewritten);
    }

    private static function orkPublicOrigin(): string
    {
        $base = rtrim(trim((string) ($_ENV['ORK_BASE_URL'] ?? '')), '/');
        if ($base === '') {
            return '';
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    private static function fixDuplicateQueryMarkers(string $url): string
    {
        $qPos = strpos($url, '?');
        if ($qPos === false) {
            return $url;
        }

        $prefix = substr($url, 0, $qPos + 1);
        $query = substr($url, $qPos + 1);

        return $prefix . str_replace('?', '&', $query);
    }
}
