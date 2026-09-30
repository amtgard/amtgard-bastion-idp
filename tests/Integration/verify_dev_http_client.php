<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\Google;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$environment = getenv('ENVIRONMENT') ?: ($_ENV['ENVIRONMENT'] ?? '');
if ($environment !== 'DEV') {
    fwrite(STDERR, "Expected ENVIRONMENT=DEV, got {$environment}\n");
    exit(1);
}

$container = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$google = $container->get(Google::class);
$httpClient = $google->getHttpClient();

if ($httpClient instanceof Client) {
    fwrite(STDOUT, "Social OAuth HTTP client is real Guzzle.\n");
    exit(0);
}

fwrite(STDERR, 'Social OAuth HTTP client is ' . $httpClient::class . ", expected Guzzle Client\n");
exit(1);
