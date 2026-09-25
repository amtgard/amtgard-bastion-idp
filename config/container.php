<?php

declare(strict_types=1);

use Amtgard\IdP\Middleware\ManagementMiddleware;
use Amtgard\IdP\Utility\AppleLoginFeature;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\BuildInfo;
use Amtgard\IdP\Utility\Config\EnvironmentLoader;
use Amtgard\IdP\Utility\Constants;
use Amtgard\IdP\Utility\Security\CsrfTokenManager;
use Amtgard\IdP\Utility\Security\CurrentUserResolver;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$envLoad = EnvironmentLoader::builder()
    ->liveIncludesDir(__DIR__ . '/container')
    ->integIncludesDir(__DIR__ . '/container')
    ->environment($_ENV['ENVIRONMENT'] ?? 'DEV')
    ->integEnvironment('DEV_INTEG')
    ->build();

$envLoad->register('persistence', 'persistence.php', null);
$envLoad->register('sessions-redis', 'sessions-redis.php', null);
$envLoad->register('oauth-server', 'oauth-server.php', null);
$envLoad->register('resources', 'resources.php', null);
$envLoad->register('auth-providers', 'auth-providers.php', 'auth-providers.integ.php');
$envLoad->register('ork', 'ork.php', 'ork.integ.php');
$envLoad->register('mailbox', 'mailbox.php', null);

return array_merge(
    require $envLoad->emit('persistence'),
    require $envLoad->emit('sessions-redis'),
    require $envLoad->emit('oauth-server'),
    require $envLoad->emit('resources'),
    require $envLoad->emit('mailbox'),
    require $envLoad->emit('auth-providers'),
    require $envLoad->emit('ork'),
    [
        LoggerInterface::class => function () {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $level = ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? Logger::DEBUG : Logger::NOTICE;
            $logger = new Logger('app');
            $logger->pushHandler(new WhatFailureGroupHandler([
                new StreamHandler($logDir . '/app.log', $level),
                new ErrorLogHandler(level: $level),
            ]));

            return $logger;
        },

        ManagementMiddleware::class => function (ContainerInterface $container) {
            return new ManagementMiddleware();
        },

        CurrentUserResolverInterface::class => function (ContainerInterface $container) {
            return CurrentUserResolver::builder()
                ->userRepository($container->get(UserRepository::class))
                ->build();
        },

        TwigEnvironment::class => function (ContainerInterface $container) {
            $loader = new FilesystemLoader(__DIR__ . '/../templates');
            $twig = new TwigEnvironment($loader, [
                'cache' => __DIR__ . '/cache/twig',
                'auto_reload' => true,
            ]);
            $twig->addFunction(new TwigFunction('csrf_token', fn () => CsrfTokenManager::getOrCreate()));
            $twig->addGlobal('appleLoginEnabled', AppleLoginFeature::isEnabled());
            $twig->addGlobal('appVersion', BuildInfo::getVersion());
            return $twig;
        },

        AuthorizedClients::class => function (ContainerInterface $container) {
            return AuthorizedClients::builder()
                ->clientIds([Constants::$AMTGARD_IDP_CLIENT_ID])
                ->build();
        },
    ],
);
