#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\IdP\Utility\Log\Sqlite\LogPathResolver;
use Amtgard\IdP\Utility\Log\Sqlite\LogSpoolDrainer;
use Amtgard\IdP\Utility\Log\Sqlite\MonologJsonLineParser;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogQuery;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogSchema;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$paths = LogPathResolver::fromEnv($root);
$schema = new SqliteLogSchema();

if (! isset($argv[1])) {
    fwrite(STDERR, "Usage: idp-logs query --request-id=ID | bundle --request-id=ID [--out=path.jsonl] | drain-once\n");
    exit(1);
}

$command = $argv[1];
if ($command === 'drain-once') {
    $count = (new LogSpoolDrainer($paths, $schema, new MonologJsonLineParser()))->drainOnce();
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

$query = new SqliteLogQuery($paths, $schema);
$lines = $query->rawLinesForRequestId($requestId);
if ($command === 'query') {
    foreach ($lines as $line) {
        fwrite(STDOUT, $line . "\n");
    }
    exit(0);
}

$payload = implode("\n", $lines) . ($lines === [] ? '' : "\n");
if ($outPath === '') {
    $outPath = $paths->root() . '/exports/request-' . $requestId . '.jsonl';
}
$paths->ensureDirectory($outPath);
if (file_put_contents($outPath, $payload) === false) {
    fwrite(STDERR, "Unable to write bundle: {$outPath}\n");
    exit(1);
}
fwrite(STDOUT, 'wrote ' . count($lines) . " line(s) to {$outPath}\n");
