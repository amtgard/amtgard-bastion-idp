#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Utility\CallConsumersBackoff;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use Psr\Log\LoggerInterface;

/**
 * Isolated JWT PVH refresh worker (D15).
 *
 * v1.1.2 SetQueue: redrive + subscribe + callConsumers (no pump).
 * Library always commit()s after the subscriber callback, including on
 * exception. Processing failures must log and re-publish the same key/message.
 */

ini_set('memory_limit', getenv('JWT_PVH_WORKER_MEMORY_LIMIT') ?: '256M');

$container = require __DIR__ . '/../config/bootstrap.php';

$logger = $container->get(LoggerInterface::class);
$pubSub = $container->get(PubSubQueue::class);
$handle = $container->get(PvhQueueHandle::class);
$queueName = $handle->getHandle();
$service = $container->get(JwtPvhRefreshService::class);

$stopping = false;
$stop = static function () use (&$stopping): void {
    $stopping = true;
};

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$logger->notice('jwt pvh worker started', [
    'queue' => $queueName,
]);

$pubSub->redrive($queueName);

$processed = false;
$pubSub->subscribe($queueName, function ($key, $message) use ($pubSub, $queueName, $service, $logger, &$processed): void {
    $processed = true;
    $payload = null;
    try {
        $payload = json_decode((string) $message, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || !isset($payload['user_uuid'], $payload['aud'])
            || !is_string($payload['user_uuid'])
            || !is_string($payload['aud'])
            || $payload['user_uuid'] === ''
            || $payload['aud'] === ''
        ) {
            $logger->error('jwt pvh worker dropped malformed message', [
                'key' => $key,
            ]);

            return;
        }

        $logger->notice('jwt pvh worker dequeued', [
            'key' => $key,
            'user_uuid' => $payload['user_uuid'],
            'aud' => $payload['aud'],
        ]);
        // Long-running CLI: AARO identity-map would otherwise keep the first
        // user_jwt_generations row forever and miss MySQL policy_hash changes.
        EntityManager::getManager()->clearAll();
        $service->refresh($payload['user_uuid'], $payload['aud']);
    } catch (Throwable $e) {
        $logger->error('jwt pvh worker job failed; re-publishing', [
            'key' => $key,
            'detail' => $e->getMessage(),
        ]);
        $republishKey = $key;
        if (isset($payload) && is_array($payload) && isset($payload['user_uuid'], $payload['aud'])
            && is_string($payload['user_uuid']) && is_string($payload['aud'])
        ) {
            $republishKey = $payload['user_uuid'] . ':' . $payload['aud'];
        }
        $pubSub->publish($queueName, $republishKey, (string) $message);
    }
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
