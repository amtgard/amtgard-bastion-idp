<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Persistence\Client\Repositories\OrkClaimPendingRepository;
use Amtgard\IdP\Persistence\Client\Repositories\OrkClaimSendNonceRepository;
use Amtgard\IdP\Services\Mail\IntegRecordingOutboundMail;
use Amtgard\IdP\Services\Ork\OrkClaimSendNonceService;
use Amtgard\IdP\Services\Mail\OutboundMail;
use Amtgard\IdP\Services\MailboxChallengeService;
use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    MailboxChallengeRepository::class => function (EntityManager $em, Database $database) {
        $repository = $em->getRepository(MailboxChallengeRepository::class);
        if ($repository instanceof MailboxChallengeRepository) {
            $repository->useDatabase($database);
        }

        return $repository;
    },

    OutboundMail::class => function (ContainerInterface $container) {
        $redis = PubSubRedisConfig::connect(new \Redis());

        return new IntegRecordingOutboundMail(
            $redis,
            $container->get(LoggerInterface::class),
        );
    },

    MailboxChallengeService::class => function (ContainerInterface $container) {
        return new MailboxChallengeService(
            $container->get(MailboxChallengeRepository::class),
            $container->get(OutboundMail::class),
            $container->get(LoggerInterface::class),
        );
    },

    OrkClaimSendNonceRepository::class => function (Database $database) {
        $repository = new OrkClaimSendNonceRepository();
        $repository->useDatabase($database);

        return $repository;
    },

    OrkClaimPendingRepository::class => function (Database $database) {
        $repository = new OrkClaimPendingRepository();
        $repository->useDatabase($database);

        return $repository;
    },

    OrkClaimSendNonceService::class => function (ContainerInterface $container) {
        return new OrkClaimSendNonceService(
            $container->get(OrkClaimSendNonceRepository::class),
            $container->get(OrkClaimPendingRepository::class),
        );
    },
];
