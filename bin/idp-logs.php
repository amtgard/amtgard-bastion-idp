#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\Log\IdpLogsCli;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

if (! isset($argv[1])) {
    fwrite(STDERR, "Usage: idp-logs query --request-id=ID | bundle --request-id=ID [--out=path.jsonl] | drain-once\n");
    exit(1);
}

$command = $argv[1];
$logger = new Logger('idp-logs-cli');
$logger->pushHandler(new StreamHandler('php://stderr', \Monolog\Level::Info));
$cli = IdpLogsCli::fromProjectRoot($root, $logger);

if ($command === 'drain-once') {
    $count = $cli->drainOnce();
    fwrite(STDOUT, "drained {$count} line(s)\n");
    exit(0);
}

if ($command !== 'query' && $command !== 'bundle') {
    fwrite(STDERR, "Unknown command: {$command}\n");
    exit(1);
}

$requestId = '';
$outPath = '';
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--request-id=')) {
        $requestId = substr($arg, strlen('--request-id='));
    }
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, strlen('--out='));
    }
}

if ($requestId === '') {
    fwrite(STDERR, "--request-id is required\n");
    exit(1);
}

try {
    if ($command === 'query') {
        foreach ($cli->queryRequestId($requestId) as $line) {
            fwrite(STDOUT, $line . "\n");
        }
        exit(0);
    }

    $bundle = $cli->bundleRequestId($requestId, $outPath !== '' ? $outPath : null);
    fwrite(STDOUT, 'wrote ' . $bundle['row_count'] . ' line(s) to ' . $bundle['path'] . "\n");
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
