<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use Psr\Log\LoggerInterface;
use Redis;

class RedisCacheRepository
{
    private const PVH_KEY_PREFIX = 'pvh:';

    private LoggerInterface $logger;
    private Redis $redis;
    private PubSubQueue $pubSubQueue;
    private PvhQueueHandle $pvhQueueHandle;

    public function __construct(
        LoggerInterface $logger,
        Redis $redis,
        PubSubQueue $pubSubQueue,
        PvhQueueHandle $pvhQueueHandle,
    ) {
        $this->logger = $logger;
        $this->redis = $redis;
        $this->pubSubQueue = $pubSubQueue;
        $this->pvhQueueHandle = $pvhQueueHandle;
    }

    public function getPvhRecord(string $userUuid, string $aud): ?PvhCacheRecord
    {
        $cached = $this->redis->get(self::pvhKey($userUuid, $aud));
        if (!$cached) {
            return null;
        }

        return PvhCacheRecord::fromJson((string) $cached);
    }

    public function setPvhRecord(PvhCacheRecord $record): void
    {
        $this->redis->set(self::pvhKey($record->getUserUuid(), $record->getAud()), $record->toJson());
    }

    /**
     * Logout-only: SCAN-deletes pvh:{userId}:* JSON records. Also DELs the
     * leftover UUID key so pre-M7 serialize blobs do not survive logout.
     * Client IAM claim/metadata paths must not call this.
     */
    public function invalidateUser(string $userId): void
    {
        $this->redis->del($userId);

        $iterator = null;
        $pattern = self::PVH_KEY_PREFIX . $userId . ':*';
        while (false !== ($keys = $this->redis->scan($iterator, $pattern))) {
            if ($keys !== []) {
                $this->redis->del($keys);
            }
        }
    }

    public function queueUserValidation(string $userUuid, string $aud): void
    {
        $queue = $this->pvhQueueHandle->getHandle();
        $this->pubSubQueue->publish(
            $queue,
            $userUuid . ':' . $aud,
            json_encode(['user_uuid' => $userUuid, 'aud' => $aud], JSON_THROW_ON_ERROR)
        );
        $this->logger->notice('jwt pvh enqueue', [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'queue' => $queue,
        ]);
    }

    public static function pvhKey(string $userUuid, string $aud): string
    {
        return self::PVH_KEY_PREFIX . $userUuid . ':' . $aud;
    }
}
