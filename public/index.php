<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Prod docker injects .prod.env via compose; .env overrides with live server values.
if (is_readable(__DIR__ . '/../.prod.env')) {
    Dotenv\Dotenv::createMutable(__DIR__ . '/..', '.prod.env')->safeLoad();
}
Dotenv\Dotenv::createMutable(__DIR__ . '/..')->safeLoad();

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
error_reporting($debug ? E_ALL : (E_ERROR | E_PARSE));
ini_set('display_errors', $debug ? '1' : '0');

// Set up dependency injection container
$containerBuilder = new ContainerBuilder();

// Add container definitions
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');

$containerBuilder->useAutowiring(true);

// Build the container
$container = $containerBuilder->build();

// Create the app
$app = Bridge::create($container);

// Register middleware
(require __DIR__ . '/../config/middleware.php')($app);

// Register routes
(require __DIR__ . '/../config/routes.php')($app);

// Run the app
$app->run();
