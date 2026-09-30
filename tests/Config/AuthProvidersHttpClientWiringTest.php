<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Utility\Http\SocialOAuthHttpClient;
use DI\Container;
use DI\ContainerBuilder;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use PHPUnit\Framework\TestCase;
use Wohali\OAuth2\Client\Provider\Discord;

final class AuthProvidersHttpClientWiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $_ENV['GOOGLE_CLIENT_ID'] = 'test_google_client_id';
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'test_google_client_secret';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'http://localhost:8080/auth/google/callback';
        $_ENV['FACEBOOK_CLIENT_ID'] = 'test_facebook_client_id';
        $_ENV['FACEBOOK_CLIENT_SECRET'] = 'test_facebook_client_secret';
        $_ENV['FACEBOOK_REDIRECT_URI'] = 'http://localhost:8080/auth/facebook/callback';
        $_ENV['DISCORD_CLIENT_ID'] = 'test_discord_client_id';
        $_ENV['DISCORD_CLIENT_SECRET'] = 'test_discord_client_secret';
        $_ENV['DISCORD_REDIRECT_URI'] = 'http://localhost:8080/auth/discord/callback';

        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container/auth-providers.php');
        $this->container = $builder->build();
    }

    public function testSocialProvidersReceiveSharedContainerHttpClient(): void
    {
        $httpClient = $this->container->get(SocialOAuthHttpClient::class);
        $google = $this->container->get(Google::class);
        $facebook = $this->container->get(Facebook::class);
        $discord = $this->container->get(Discord::class);

        $this->assertInstanceOf(ClientInterface::class, $httpClient);
        $this->assertSame($httpClient, $google->getHttpClient());
        $this->assertSame($httpClient, $facebook->getHttpClient());
        $this->assertSame($httpClient, $discord->getHttpClient());
        $this->assertSame($httpClient, $this->container->get(SocialOAuthHttpClient::class));
    }
}
