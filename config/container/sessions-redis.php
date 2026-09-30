<?php

declare(strict_types=1);

use Amtgard\IdP\Persistence\Server\Repositories\RedisCacheRepository;
use Amtgard\IdP\Utility\PubSubQueueHandle;
use Amtgard\IdP\Utility\Pvh\PvhAuthorizationGate;
use Amtgard\IdP\Utility\PvhQueueHandle;
use Amtgard\IdP\Utility\PvhSetQueue;
use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisDataStructureConfig;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisHashSetFactory;
use Amtgard\SetQueue\DataStructure\Impl\Redis\RedisRedrivableQueueFactory;
use Amtgard\SetQueue\DataStructure\SetQueue;
use Amtgard\SetQueue\PubSubQueue;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Redis;

return [
    RedisDataStructureConfig::class => function (ContainerInterface $container) {
        return PubSubRedisConfig::dataStructureConfig();
    },

    Redis::class => function (ContainerInterface $container) {
        return PubSubRedisConfig::connect(new Redis());
    },

    PubSubQueueHandle::class => function (ContainerInterface $container) {
        $queue = $container->get(SetQueue::class);
        $pubSub = $container->get(PubSubQueue::class);
        $queueName = PubSubRedisConfig::queueName();
        $pubSub->addQueue($queueName, $queue);

        return PubSubQueueHandle::builder()->handle($queueName)->build();
    },

    SetQueue::class => function (ContainerInterface $container) {
        $hashSetFactory = new RedisHashSetFactory();
        $redrivableQueueFactory = new RedisRedrivableQueueFactory();
        $config = $container->get(RedisDataStructureConfig::class);
        $queue = new SetQueue(PubSubRedisConfig::queueName(), $config, $hashSetFactory, $redrivableQueueFactory);
        return $queue;
    },

    PvhSetQueue::class => function (ContainerInterface $container) {
        $hashSetFactory = new RedisHashSetFactory();
        $redrivableQueueFactory = new RedisRedrivableQueueFactory();
        $config = $container->get(RedisDataStructureConfig::class);

        return new PvhSetQueue(PubSubRedisConfig::pvhQueueName(), $config, $hashSetFactory, $redrivableQueueFactory);
    },

    PvhQueueHandle::class => function (ContainerInterface $container) {
        $queue = $container->get(PvhSetQueue::class);
        $pubSub = $container->get(PubSubQueue::class);
        $queueName = PubSubRedisConfig::pvhQueueName();
        $pubSub->addQueue($queueName, $queue);

        return PvhQueueHandle::builder()->handle($queueName)->build();
    },

    PubSubQueue::class => function (ContainerInterface $container) {
        $redis = $container->get(Redis::class);
        if (!$redis->isConnected()) {
            throw new \Exception('Redis not connected');
        }
        $pubSub = new PubSubQueue();

        return $pubSub;
    },

    PvhAuthorizationGate::class => function (ContainerInterface $container) {
        return PvhAuthorizationGate::builder()
            ->redisCacheRepository($container->get(RedisCacheRepository::class))
            ->logger($container->get(LoggerInterface::class))
            ->build();
    },
];
