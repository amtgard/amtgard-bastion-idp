<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Services\Mail\IntegRecordingOutboundMail;
use Amtgard\IdP\Services\Mail\OutboundMail;
use Amtgard\IdP\Utility\Http\SocialOAuthHttpClient;
use DevIntegHttpClient;
use DI\ContainerBuilder;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\Google;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/config/container/integ/DevIntegHttpClient.php';

final class DevIntegContainerModeTest extends TestCase
{
    protected function tearDown(): void
    {
        $_ENV['ENVIRONMENT'] = 'DEV';
        parent::tearDown();
    }

    public function testDevIntegEnvironmentWiresGoogleProviderWithFakeHttpClient(): void
    {
        $container = $this->buildAuthContainer('DEV_INTEG');
        $google = $container->get(Google::class);

        $this->assertInstanceOf(Google::class, $google);
        $this->assertInstanceOf(DevIntegHttpClient::class, $google->getHttpClient());
    }

    public function testDevIntegEnvironmentUsesRecordingOutboundMail(): void
    {
        try {
            $container = $this->buildAuthContainer('DEV_INTEG');
            $mail = $container->get(OutboundMail::class);
        } catch (\RedisException) {
            $this->markTestSkipped('Pub/sub Redis is required to resolve OutboundMail in DEV_INTEG');
        }

        $this->assertInstanceOf(IntegRecordingOutboundMail::class, $mail);
    }

    public function testDevEnvironmentWiresGoogleProviderWithRealGuzzleClient(): void
    {
        $container = $this->buildAuthContainer('DEV');
        $google = $container->get(Google::class);
        $shared = $container->get(SocialOAuthHttpClient::class);

        $this->assertInstanceOf(Google::class, $google);
        $this->assertInstanceOf(Client::class, $google->getHttpClient());
        $this->assertNotInstanceOf(DevIntegHttpClient::class, $google->getHttpClient());
        $this->assertSame($shared, $google->getHttpClient());
    }

    private function buildAuthContainer(string $environment): \DI\Container
    {
        $_ENV['ENVIRONMENT'] = $environment;
        $_ENV['GOOGLE_CLIENT_ID'] = 'test_google_client_id';
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'test_google_client_secret';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'http://localhost:8080/auth/google/callback';
        $_ENV['FACEBOOK_CLIENT_ID'] = 'test_facebook_client_id';
        $_ENV['FACEBOOK_CLIENT_SECRET'] = 'test_facebook_client_secret';
        $_ENV['FACEBOOK_REDIRECT_URI'] = 'http://localhost:8080/auth/facebook/callback';
        $_ENV['DISCORD_CLIENT_ID'] = 'test_discord_client_id';
        $_ENV['DISCORD_CLIENT_SECRET'] = 'test_discord_client_secret';
        $_ENV['DISCORD_REDIRECT_URI'] = 'http://localhost:8080/auth/discord/callback';
        $_ENV['MAILBOX_CODE_PEPPER'] = 'integ-unit-test-mailbox-pepper-value';

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        return $builder->build();
    }
}
