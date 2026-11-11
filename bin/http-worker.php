<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\Http\WorkerRequestScope;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

if (!function_exists('frankenphp_handle_request')) {
    fwrite(STDERR, "FrankenPHP worker requires the frankenphp extension.\n");
    exit(1);
}

require_once $projectRoot . '/config/http-app.php';

$app = idp_create_http_app();

while (frankenphp_handle_request(static function () use ($app): bool {
    $outputBufferLevelAtStart = ob_get_level();
    try {
        $app->run();
    } finally {
        WorkerRequestScope::finish($outputBufferLevelAtStart);
    }

    return true;
})) {
}
