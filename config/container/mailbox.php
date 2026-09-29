<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mail\MailCarrier;
use Amtgard\IdP\Services\Mail\MailCarrierSettings;
use Amtgard\IdP\Services\Mail\OutboundMail;
use Amtgard\IdP\Services\Mail\OutboundMailFactory;
use Amtgard\IdP\Services\MailboxChallengeService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    MailboxChallengeRepository::class => function (EntityManager $em) {
        return $em->getRepository(MailboxChallengeRepository::class);
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
