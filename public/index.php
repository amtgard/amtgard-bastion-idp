<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;

$container = require __DIR__ . '/../config/bootstrap.php';

$app = Bridge::create($container);

(require __DIR__ . '/../config/middleware.php')($app);
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
