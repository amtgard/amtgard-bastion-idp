<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/http-app.php';

$app = idp_create_http_app();
$app->run();
