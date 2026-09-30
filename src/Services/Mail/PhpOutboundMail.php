<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;

final class PhpOutboundMail implements OutboundMail
{
    public function __construct(
        private LoggerInterface $logger,
        private ?\Closure $transport = null,
    ) {
    }

    public function send(string $to, string $subject, string $text): void
    {
        $transport = $this->transport ?? static function (string $recipient, string $mailSubject, string $body): bool {
            return mail($recipient, $mailSubject, $body);
        };
        $ok = $transport($to, $subject, $text);
        $this->logger->info('mail.sent', [
            'carrier' => MailCarrier::Php->value,
            'sent_to_hash' => hash('sha256', $to),
            'subject' => $subject,
        ]);
        if (!$ok) {
            throw new \RuntimeException('mail send failed');
        }
    }
}
