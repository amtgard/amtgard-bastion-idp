<?php

require __DIR__ . '/vendor/autoload.php';

// Load env the same way as the web app: .prod.env base, then .env overrides.
// Do not use .phinx.env alone — it often still has dev defaults (amtgard-idp-db).
if (is_readable(__DIR__ . '/.prod.env')) {
    Dotenv\Dotenv::createMutable(__DIR__, '.prod.env')->safeLoad();
}
Dotenv\Dotenv::createMutable(__DIR__)->safeLoad();

if (empty($_ENV['DB_HOST']) && is_readable(__DIR__ . '/.phinx.env')) {
    Dotenv\Dotenv::createMutable(__DIR__, '.phinx.env')->safeLoad();
}

$config = [
    'paths' => [
        'migrations' => 'db/migrations',
        'seeds' => 'db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'],
            'name' => $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'pass' => $_ENV['DB_PASS'],
            'port' => $_ENV['DB_PORT'],
            'charset' => 'utf8mb4',
        ],
        'production' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'],
            'name' => $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'pass' => $_ENV['DB_PASS'],
            'port' => $_ENV['DB_PORT'],
            'charset' => 'utf8mb4',
        ],
    ],
];

return $config;
