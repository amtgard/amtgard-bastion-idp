<?php

declare(strict_types=1);

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\IdP\Utility\CachedValidatedUserEntity;
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

    public function isUserInCache(string $userId): bool {
        return $this->redis->get($userId) ? true : false;
    }

    public function getUser(string $userId): ?CachedValidatedUserEntity {
        $cached = $this->redis->get($userId);
        if (!$cached) {
            return null;
        }

        $user = unserialize($cached);
        if (!$user instanceof CachedValidatedUserEntity) {
            return null;
        }

        if (method_exists($user, '__wakeup')) {
            $user->__wakeup();
        }

        return $user;
    }

    public function setUser(CachedValidatedUserEntity $userEntity): void {
        $this->redis->set($userEntity->getUserId(), serialize($userEntity));
    }

    public function cacheValidatedUser(string $userId, string $email, string $jwt): void
    {
        $this->setUser(CachedValidatedUserEntity::builder()
            ->userId($userId)
            ->email($email)
            ->jwt($jwt)
            ->build());
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
     * Deletes the legacy serialize cache key ($userId) so logout/IAM still
     * clear today's validate path, and SCAN-deletes pvh:{userId}:* for the
     * new JSON records.
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
        $this->pubSubQueue->publish(
            $this->pvhQueueHandle->getHandle(),
            $userUuid . ':' . $aud,
            json_encode(['user_uuid' => $userUuid, 'aud' => $aud], JSON_THROW_ON_ERROR)
        );
    }

    public static function pvhKey(string $userUuid, string $aud): string
    {
        return self::PVH_KEY_PREFIX . $userUuid . ':' . $aud;
    }
}
