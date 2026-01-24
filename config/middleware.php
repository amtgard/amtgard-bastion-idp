<?php
declare(strict_types=1);

use Slim\App;
use Slim\Middleware\MethodOverrideMiddleware;
use Amtgard\IdP\Middleware\SessionMiddleware;
use Amtgard\IdP\Middleware\JsonBodyParserMiddleware;

return function (App $app) {
    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // Add the JSON body parser middleware
    $app->add(new JsonBodyParserMiddleware());

    // Add session middleware
    $app->add(new SessionMiddleware());

    // Add method override middleware to support PUT, DELETE, etc. with a form
    $app->add(new MethodOverrideMiddleware());

    // Add Error Middleware
    $errorMiddleware = $app->addErrorMiddleware(
        $_ENV['APP_DEBUG'] === 'true',
        true,
        true
    );

    return $errorMiddleware;
};