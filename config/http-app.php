<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * @return App<ContainerInterface>
 */
function idp_create_http_app(?ContainerInterface $container = null): App
{
    if ($container === null) {
        $container = require __DIR__ . '/bootstrap.php';
    }

    $app = Bridge::create($container);

    (require __DIR__ . '/middleware.php')($app);
    (require __DIR__ . '/routes.php')($app);

    return $app;
}
