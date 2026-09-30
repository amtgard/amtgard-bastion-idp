<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Builds the shared Guzzle client League OAuth2 providers use by default (Factory).
 */
final class SocialOAuthHttpClient
{
    public static function createDefault(): ClientInterface
    {
        // League AbstractProvider intersects provider options with timeout/proxy/(verify);
        // our provider configs pass none, so League would use `new Client([])`.
        return new Client([]);
    }
}
