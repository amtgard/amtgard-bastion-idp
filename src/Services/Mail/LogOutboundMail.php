<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;

final class LogOutboundMail implements OutboundMail
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        $this->logger->info('mail.sent', [
            'carrier' => MailCarrier::Log->value,
            'sent_to_hash' => hash('sha256', $to),
            'subject' => $subject,
        ]);
    }
}
