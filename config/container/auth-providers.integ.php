<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\AppleLoginFeature;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Psr\Container\ContainerInterface;
use Wohali\OAuth2\Client\Provider\Discord;

require_once __DIR__ . '/integ/DevIntegHttpClient.php';

return [
    DevIntegHttpClient::class => static function (ContainerInterface $container): ClientInterface {
        return new DevIntegHttpClient($container->get(\Psr\Log\LoggerInterface::class));
    },

    Google::class => function (ContainerInterface $container) {
        return new Google([
            'clientId' => $_ENV['GOOGLE_CLIENT_ID'],
            'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirectUri' => $_ENV['GOOGLE_REDIRECT_URI'],
            'scopes' => ['email', 'profile'],
        ], [
            'httpClient' => $container->get(DevIntegHttpClient::class),
        ]);
    },

    Facebook::class => function (ContainerInterface $container) {
        return new Facebook([
            'clientId' => $_ENV['FACEBOOK_CLIENT_ID'],
            'clientSecret' => $_ENV['FACEBOOK_CLIENT_SECRET'],
            'redirectUri' => $_ENV['FACEBOOK_REDIRECT_URI'],
            'graphApiVersion' => 'v12.0',
        ], [
            'httpClient' => $container->get(DevIntegHttpClient::class),
        ]);
    },

    Discord::class => function (ContainerInterface $container) {
        return new Discord([
            'clientId' => $_ENV['DISCORD_CLIENT_ID'],
            'clientSecret' => $_ENV['DISCORD_CLIENT_SECRET'],
            'redirectUri' => $_ENV['DISCORD_REDIRECT_URI'],
        ], [
            'httpClient' => $container->get(DevIntegHttpClient::class),
        ]);
    },

    Apple::class => function (ContainerInterface $container) {
        if (!AppleLoginFeature::isEnabled()) {
            throw new \RuntimeException('Apple login is not enabled.');
        }

        JWT::$leeway = 60;

        $projectRoot = dirname(__DIR__, 2);
        $keyFilePath = (string) ($_ENV['APPLE_KEY_FILE_PATH'] ?? '');
        if ($keyFilePath === '' || !is_readable($keyFilePath)) {
            $keyFilePath = $projectRoot . '/tests/fixtures/integ-apple-es256.pem';
        } elseif ($keyFilePath[0] !== '/') {
            $keyFilePath = $projectRoot . '/' . ltrim($keyFilePath, '/');
        }

        return new Apple([
            'clientId' => $_ENV['APPLE_CLIENT_ID'],
            'teamId' => $_ENV['APPLE_TEAM_ID'],
            'keyFileId' => $_ENV['APPLE_KEY_FILE_ID'],
            'keyFilePath' => $keyFilePath,
            'redirectUri' => $_ENV['APPLE_REDIRECT_URI'],
        ], [
            'httpClient' => $container->get(DevIntegHttpClient::class),
        ]);
    },
];
