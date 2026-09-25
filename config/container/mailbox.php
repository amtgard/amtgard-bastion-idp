<?php

declare(strict_types=1);

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Repositories\MailboxChallengeRepository;
use Amtgard\IdP\Services\Mailbox\LogOutboundMail;
use Amtgard\IdP\Services\Mailbox\OutboundMail;
use Amtgard\IdP\Services\Mailbox\SmtpOutboundMail;
use Amtgard\IdP\Services\MailboxChallengeService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    MailboxChallengeRepository::class => function (EntityManager $em) {
        return $em->getRepository(MailboxChallengeRepository::class);
    },

    OutboundMail::class => function (ContainerInterface $container) {
        $dsn = trim((string) ($_ENV['MAIL_DSN'] ?? ''));
        if ($dsn !== '') {
            return new SmtpOutboundMail($dsn, $container->get(LoggerInterface::class));
        }

        return new LogOutboundMail($container->get(LoggerInterface::class));
    },

    MailboxChallengeService::class => function (ContainerInterface $container) {
        return new MailboxChallengeService(
            $container->get(MailboxChallengeRepository::class),
            $container->get(OutboundMail::class),
            $container->get(LoggerInterface::class),
        );
    },
];
