#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Utility\CallConsumersBackoff;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Pvh\JwtPvhQueueProcessor;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;

/**
 * Isolated JWT PVH refresh worker (D15).
 *
 * v1.1.2 SetQueue: redrive + subscribe + callConsumers (no pump).
 * Library always commit()s after the subscriber callback, including on
 * exception. Processing failures must log and re-publish the same key/message.
 */

ini_set('memory_limit', getenv('JWT_PVH_WORKER_MEMORY_LIMIT') ?: '256M');

$container = require __DIR__ . '/../config/bootstrap.php';

$idpLogging = $container->get(IdpLogging::class);
$pvhLog = $idpLogging->pvh();
$pubSub = $container->get(PubSubQueue::class);
$handle = $container->get(PvhQueueHandle::class);
$queueName = $handle->getHandle();
$service = $container->get(JwtPvhRefreshService::class);
$jobId = bin2hex(random_bytes(8));

$stopping = false;
$stop = static function () use (&$stopping): void {
    $stopping = true;
};

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$pvhLog->notice('jwt pvh worker started', [
    'queue' => $queueName,
    'job_id' => $jobId,
    'correlation_root' => 'worker',
]);

$pubSub->redrive($queueName);

$processor = new JwtPvhQueueProcessor($service, $pubSub, $queueName, $pvhLog, $jobId);

$processed = false;
$pubSub->subscribe($queueName, function ($key, $message) use ($processor, &$processed): void {
    $processed = true;
    $processor->onMessage($key, $message);
});

$backoff = new CallConsumersBackoff();

while (!$stopping) {
    $processed = false;
    $pubSub->callConsumers($queueName, 1);
    $sleepMs = $backoff->next($processed);
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

exit(0);
