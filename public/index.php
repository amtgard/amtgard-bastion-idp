<?php

declare(strict_types=1);

//error_reporting(E_ALL);
//ini_set('display_errors', '1');

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Set up dependency injection container
$containerBuilder = new ContainerBuilder();

// Add container definitions
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');

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