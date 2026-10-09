<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\IdP\Utility\Pvh\PvhQueueMessage;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\SetQueue\PubSubQueue;
use Redis;

class RedisCacheRepository
{
    private const PVH_KEY_PREFIX = 'pvh:';

    public function __construct(
        private IdpLogging $idpLogging,
        private Redis $redis,
        private PubSubQueue $pubSubQueue,
        private PvhQueueHandle $pvhQueueHandle,
    ) {
    }

    public function getPvhRecord(string $userUuid, string $aud): ?PvhCacheRecord
    {
        $cached = $this->redis->get(self::pvhKey($userUuid, $aud));
        if (!$cached) {
            $this->idpLogging->pvh()->debug('pvh redis cache miss', [
                'user_uuid' => $userUuid,
                'aud' => $aud,
                'cache' => 'miss',
            ]);

            return null;
        }

        $record = PvhCacheRecord::fromJson((string) $cached);
        if ($record === null) {
            $this->idpLogging->pvh()->debug('pvh redis cache miss', [
                'user_uuid' => $userUuid,
                'aud' => $aud,
                'cache' => 'miss',
            ]);
        }

        return $record;
    }

    public function setPvhRecord(PvhCacheRecord $record): void
    {
        $this->redis->set(self::pvhKey($record->getUserUuid(), $record->getAud()), $record->toJson());
        $this->idpLogging->pvh()->debug('pvh redis cache write', [
            'user_uuid' => $record->getUserUuid(),
            'aud' => $record->getAud(),
        ]);
    }

    /**
     * Pre-M7 serialize blob at key=user UUID (not pvh:{uuid}:{aud}).
     */
    public function hasLegacyUserEntry(string $userUuid): bool
    {
        return (bool) $this->redis->exists($userUuid);
    }

    public function deleteLegacyUserEntry(string $userUuid): void
    {
        $this->redis->del($userUuid);
    }

    /**
     * Logout-only: SCAN-deletes pvh:{userId}:* JSON records. Also DELs the
     * leftover UUID key so pre-M7 serialize blobs do not survive logout.
     * Client IAM claim/metadata paths must not call this.
     */
    public function invalidateUser(string $userId): void
    {
        $keysDeleted = (int) $this->redis->del($userId);

        $iterator = null;
        $pattern = self::PVH_KEY_PREFIX . $userId . ':*';
        while (false !== ($keys = $this->redis->scan($iterator, $pattern))) {
            if ($keys !== []) {
                $keysDeleted += (int) $this->redis->del($keys);
            }
        }

        $this->idpLogging->pvh()->info('pvh cache invalidated on logout', [
            'user_uuid' => $userId,
            'keys_deleted' => $keysDeleted,
        ]);
    }

    public function queueUserValidation(string $userUuid, string $aud): void
    {
        $queue = $this->pvhQueueHandle->getHandle();
        $requestId = RequestLogContext::id();
        $logger = $this->idpLogging->pvh();
        $payload = PvhQueueMessage::encode($userUuid, $aud, $requestId, $logger);
        $this->pubSubQueue->publish(
            $queue,
            PvhQueueMessage::builder()->userUuid($userUuid)->aud($aud)->build()->publishKey(),
            $payload
        );

        $context = [
            'user_uuid' => $userUuid,
            'aud' => $aud,
            'queue' => $queue,
        ];
        if ($requestId !== null && $requestId !== '') {
            $context['request_id'] = $requestId;
        }
        $logger->notice('jwt pvh enqueue', $context);
    }

    public static function pvhKey(string $userUuid, string $aud): string
    {
        return self::PVH_KEY_PREFIX . $userUuid . ':' . $aud;
    }
}
