<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility\Http;

use Amtgard\IdP\Utility\Http\OrkHttpClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class OrkHttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ORK_API_USER_AGENT'], $_ENV['ORK_API_REFERER']);
        parent::tearDown();
    }

    public function testCreateFromEnvironmentBuildsClientWithOrkHeaders(): void
    {
        $_ENV['ORK_API_USER_AGENT'] = 'TestAgent';
        $_ENV['ORK_API_REFERER'] = 'TestReferer';

        $client = OrkHttpClient::createFromEnvironment();

        $this->assertInstanceOf(Client::class, $client);
        $config = $client->getConfig();
        $this->assertFalse($config['verify']);
        $this->assertSame('TestAgent', $config['headers']['User-Agent']);
        $this->assertSame('TestReferer', $config['headers']['Referer']);
    }

    public function testCreateFromEnvironmentThrowsWhenConfigMissing(): void
    {
        unset($_ENV['ORK_API_USER_AGENT']);

        $this->expectException(\RuntimeException::class);
        OrkHttpClient::createFromEnvironment();
    }
}
