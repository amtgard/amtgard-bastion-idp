<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Http;

use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Builds the Guzzle client OrkService uses for legacy ORK JSON API calls (Factory).
 */
final class OrkHttpClient
{
    public static function createFromEnvironment(): ClientInterface
    {
        $userAgent = $_ENV['ORK_API_USER_AGENT'] ?? null;
        $referer = $_ENV['ORK_API_REFERER'] ?? null;
        // Identify to the ORK the way every other integration does: X-ORK-Client
        // names us in the ORK's api/signin tallies (it prefers this header over
        // User-Agent), and X-Ork-Key is what the Cloudflare skip rule matches.
        // Without the key the IDP is allowed only by an exact User-Agent match,
        // which anyone can send.
        $orkClient = (string) ($_ENV['ORK_API_CLIENT'] ?? 'AmtgardIDP/1.0');
        $orkKey = (string) ($_ENV['ORK_API_KEY'] ?? '');

        if (empty($userAgent) || empty($referer)) {
            IdpLoggingRuntime::app()->emergency('ork api client misconfigured', [
                'reason' => 'ork_api_config',
            ]);
            throw new \RuntimeException('Missing required ORK API configuration: ORK_API_USER_AGENT and ORK_API_REFERER must be set.');
        }

        $headers = [
            'User-Agent' => $userAgent,
            'Referer' => $referer,
            'X-ORK-Client' => $orkClient,
        ];
        // Omitted rather than sent empty: an empty key matches no skip rule and
        // would only muddy the ORK-side logs.
        if ($orkKey !== '') {
            $headers['X-Ork-Key'] = $orkKey;
        }

        return new Client([
            'verify' => false,
            'headers' => $headers,
        ]);
    }
}
