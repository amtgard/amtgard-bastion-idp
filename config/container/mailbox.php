<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mail\MailCarrier;
use Amtgard\IdP\Services\Mail\MailCarrierSettings;
use Amtgard\IdP\Services\Mail\OutboundMail;
use Amtgard\IdP\Services\Mail\OutboundMailFactory;
use Amtgard\IdP\Services\MailboxChallengeService;
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
        return (new OutboundMailFactory(
            $container->get(LoggerInterface::class),
            MailCarrierSettings::fromEnv(),
        ))->forCarrier(MailCarrier::fromEnv());
    },

    MailboxChallengeService::class => function (ContainerInterface $container) {
        return new MailboxChallengeService(
            $container->get(MailboxChallengeRepository::class),
            $container->get(OutboundMail::class),
            $container->get(LoggerInterface::class),
        );
    },
];
