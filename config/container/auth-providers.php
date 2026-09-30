<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\AppleLoginFeature;
use Firebase\JWT\JWT;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use Wohali\OAuth2\Client\Provider\Discord;

return [
    Google::class => function () {
        return new Google([
            'clientId' => $_ENV['GOOGLE_CLIENT_ID'],
            'clientSecret' => $_ENV['GOOGLE_CLIENT_SECRET'],
            'redirectUri' => $_ENV['GOOGLE_REDIRECT_URI'],
            'scopes' => ['email', 'profile'],
        ]);
    },

    Facebook::class => function () {
        return new Facebook([
            'clientId' => $_ENV['FACEBOOK_CLIENT_ID'],
            'clientSecret' => $_ENV['FACEBOOK_CLIENT_SECRET'],
            'redirectUri' => $_ENV['FACEBOOK_REDIRECT_URI'],
            'graphApiVersion' => 'v12.0',
        ]);
    },

    Discord::class => function () {
        return new Discord([
            'clientId' => $_ENV['DISCORD_CLIENT_ID'],
            'clientSecret' => $_ENV['DISCORD_CLIENT_SECRET'],
            'redirectUri' => $_ENV['DISCORD_REDIRECT_URI'],
        ]);
    },

    Apple::class => function () {
        if (!AppleLoginFeature::isEnabled()) {
            throw new \RuntimeException('Apple login is not enabled.');
        }

        JWT::$leeway = 60;

        return new Apple([
            'clientId' => $_ENV['APPLE_CLIENT_ID'],
            'teamId' => $_ENV['APPLE_TEAM_ID'],
            'keyFileId' => $_ENV['APPLE_KEY_FILE_ID'],
            'keyFilePath' => $_ENV['APPLE_KEY_FILE_PATH'],
            'redirectUri' => $_ENV['APPLE_REDIRECT_URI'],
        ]);
    },
];
