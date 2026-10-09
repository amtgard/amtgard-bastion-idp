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

        if (empty($userAgent) || empty($referer)) {
            IdpLoggingRuntime::app()->emergency('ork api client misconfigured', [
                'reason' => 'ork_api_config',
            ]);
            throw new \RuntimeException('Missing required ORK API configuration: ORK_API_USER_AGENT and ORK_API_REFERER must be set.');
        }

        return new Client([
            'verify' => false,
            'headers' => [
                'User-Agent' => $userAgent,
                'Referer' => $referer,
            ],
        ]);
    }
}
