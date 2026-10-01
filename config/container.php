<?php

declare(strict_types=1);

use Amtgard\IdP\Middleware\ManagementMiddleware;
use Amtgard\IdP\Utility\AppleLoginFeature;
use Amtgard\IdP\Utility\AuthorizedClients;
use Amtgard\IdP\Utility\BuildInfo;
use Amtgard\EnvironmentLoader\EnvironmentLoader;
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

$environment = $_ENV['ENVIRONMENT'] ?? 'DEV';
$containerDir = __DIR__ . '/container';

$envLoad = EnvironmentLoader::builder()
    ->build()
    ->defaultPath('DEV', $containerDir)
    ->defaultPath('DEV_INTEG', $containerDir);

$envLoad->register('persistence')
    ->withEnvironment('DEV', 'persistence.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'persistence.php');
$envLoad->register('sessions-redis')
    ->withEnvironment('DEV', 'sessions-redis.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'sessions-redis.php');
$envLoad->register('oauth-server')
    ->withEnvironment('DEV', 'oauth-server.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'oauth-server.php');
$envLoad->register('resources')
    ->withEnvironment('DEV', 'resources.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'resources.php');
$envLoad->register('auth-providers')
    ->withEnvironment('DEV', 'auth-providers.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'auth-providers.integ.php');
$envLoad->register('ork')
    ->withEnvironment('DEV', 'ork.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'ork.integ.php');
$envLoad->register('mailbox')
    ->withEnvironment('DEV', 'mailbox.php', EnvironmentLoader::DEFAULT)
    ->withEnvironment('DEV_INTEG', 'mailbox.integ.php');

return array_merge(
    require $envLoad->emit('persistence', $environment),
    require $envLoad->emit('sessions-redis', $environment),
    require $envLoad->emit('oauth-server', $environment),
    require $envLoad->emit('resources', $environment),
    require $envLoad->emit('mailbox', $environment),
    require $envLoad->emit('auth-providers', $environment),
    require $envLoad->emit('ork', $environment),
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
