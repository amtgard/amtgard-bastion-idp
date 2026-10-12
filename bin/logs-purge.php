#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\LogChannelFactory;
use Amtgard\IdP\Utility\Log\LogRetentionPurger;
use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Monolog\Level;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$paths = LogPathResolver::fromEnv($root);
$traceLevel = Level::Notice;
$factory = new LogChannelFactory($paths, $traceLevel, false, false, $root . '/logs/app.log');
$logging = new IdpLogging($factory);

$traceDays = (int) ($_ENV['LOG_TRACE_RETENTION_DAYS'] ?? '7');
$errorDays = (int) ($_ENV['LOG_ERROR_RETENTION_DAYS'] ?? '30');

$purger = new LogRetentionPurger($paths, $logging, $traceDays, $errorDays);
$results = $purger->purgeAll();

foreach ($results as $result) {
    fwrite(
        STDOUT,
        sprintf(
            "stream=%s deleted=%d cutoff=%s\n",
            $result['stream'],
            $result['files_deleted'],
            $result['cutoff'],
        ),
    );
}
