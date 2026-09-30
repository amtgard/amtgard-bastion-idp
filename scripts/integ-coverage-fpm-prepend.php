<?php

declare(strict_types=1);

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;
use function pcov\waiting;

use const pcov\inclusive;

/**
 * PCOV request/process shutdown hook for integ HTTP (php-fpm) and jwt-worker (CLI).
 * Enabled only when INTEG_COVERAGE_ENABLED=1 and compose integ-coverage overlay is active.
 */

$enabled = getenv('INTEG_COVERAGE_ENABLED');
if ($enabled !== false && $enabled !== '1') {
    return;
}

if (!in_array(PHP_SAPI, ['fpm-fcgi', 'cgi-fcgi', 'cli'], true)) {
    return;
}

if (!function_exists('pcov\\start')) {
    return;
}

start();

register_shutdown_function(static function (): void {
    if (!function_exists('pcov\\stop')) {
        return;
    }

    stop();

    $files = waiting();
    if ($files === []) {
        clear();

        return;
    }

    $collected = collect(inclusive, $files);
    clear();

    if ($collected === []) {
        return;
    }

    $rawDir = getenv('INTEG_COVERAGE_RAW_DIR') ?: '/var/www/idp.amtgard.com/build/integ-coverage/raw';
    if (!is_dir($rawDir) && !mkdir($rawDir, 0777, true) && !is_dir($rawDir)) {
        return;
    }

    $suffix = PHP_SAPI === 'cli' ? 'worker' : 'fpm';
    $path = sprintf(
        '%s/cov-%s-%d-%d.json',
        rtrim($rawDir, '/'),
        $suffix,
        getmypid(),
        hrtime(true),
    );

    file_put_contents($path, json_encode($collected, JSON_THROW_ON_ERROR));
});
