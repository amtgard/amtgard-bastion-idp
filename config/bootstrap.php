<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/di-container.php';

$projectRoot = dirname(__DIR__);

Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
// Vendor packages may emit PHP 8.4 deprecations; keep them out of HTML/CLI noise.
error_reporting($debug ? (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED) : (E_ERROR | E_PARSE));
ini_set('display_errors', $debug ? '1' : '0');

return idp_build_di_container($projectRoot);
