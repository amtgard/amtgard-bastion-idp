<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Http;

use Amtgard\IdP\Utility\Http\SocialOAuthHttpClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class SocialOAuthHttpClientTest extends TestCase
{
    public function testCreateDefaultBuildsGuzzleClientWithLeagueDefaults(): void
    {
        $client = SocialOAuthHttpClient::createDefault();

        $this->assertInstanceOf(Client::class, $client);
    }
}
