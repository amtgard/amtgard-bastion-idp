<?php
declare(strict_types=1);

use Amtgard\IdP\Handlers\ApiAwareErrorHandler;
use Amtgard\IdP\Handlers\NotFoundErrorHandler;
use Amtgard\IdP\Middleware\CorrelationMiddleware;
use Amtgard\IdP\Middleware\HttpObservabilityMiddleware;
use Amtgard\IdP\Middleware\SessionMiddleware;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Middleware\JsonBodyParserMiddleware;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Middleware\MethodOverrideMiddleware;
use Twig\Environment as TwigEnvironment;

return function (App $app) {
    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // Add the JSON body parser middleware
    $app->add(new JsonBodyParserMiddleware($app->getContainer()->get(IdpLogging::class)));



    // Add session middleware
    $app->add(new SessionMiddleware());

    // Add method override middleware to support PUT, DELETE, etc. with a form
    $app->add(new MethodOverrideMiddleware());

    // Add CORS middleware
    // Added last so it runs first (LIFO), handling OPTIONS requests before other middleware/routing
    $app->add(new \Amtgard\IdP\Middleware\CorsMiddleware());

    $app->add(new HttpObservabilityMiddleware($app->getContainer()->get(IdpLogging::class)));
    $app->add(new CorrelationMiddleware());

    // Log exceptions to Monolog (logs/app.log); display details only when APP_DEBUG=true
    $displayErrorDetails = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    $logger = $app->getContainer()->get(LoggerInterface::class);
    $view = $app->getContainer()->get(TwigEnvironment::class);

    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true, $logger);
    $errorMiddleware->setDefaultErrorHandler(
        new ApiAwareErrorHandler(
            $app->getCallableResolver(),
            $app->getResponseFactory(),
            $logger,
            $view
        )
    );
    $errorMiddleware->setErrorHandler(
        HttpNotFoundException::class,
        new NotFoundErrorHandler(
            $app->getCallableResolver(),
            $app->getResponseFactory(),
            $logger,
            $view
        )
    );

    return $errorMiddleware;
};
