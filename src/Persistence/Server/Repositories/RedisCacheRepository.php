<?php

namespace Amtgard\IdP\Persistence\Server\Repositories;

use Amtgard\IdP\Utility\CachedValidatedUserEntity;
use Psr\Log\LoggerInterface;
use Redis;

class RedisCacheRepository
{
    private LoggerInterface $logger;
    private Redis $redis;

    public function __construct(LoggerInterface $logger, Redis $redis) {
        $this->logger = $logger;
        $this->redis = $redis;
    }

    public function isUserInCache(string $userId): bool {
        return $this->redis->get($userId) ? true : false;
    }

    public function getUser(string $userId): ?CachedValidatedUserEntity {
        return $this->redis->get($userId) ? unserialize($this->redis->get($userId)) : null;
    }

    public function setUser(CachedValidatedUserEntity $userEntity): void {
        $this->redis->set($userEntity->getUserId(), serialize($userEntity));
    }

    public function queueUserValidation(string $userId, string $userEmail) {

    }
}