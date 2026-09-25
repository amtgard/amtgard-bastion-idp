<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

use Psr\Log\LoggerInterface;

final class SmtpOutboundMail implements OutboundMail
{
    public function __construct(
        private string $dsn,
        private LoggerInterface $logger,
        private ?\Closure $transport = null,
    ) {
        if (trim($dsn) === '') {
            throw new \InvalidArgumentException('MAIL_DSN is required for SmtpOutboundMail');
        }
    }

    public function send(string $to, string $subject, string $text): void
    {
        $sentToHash = hash('sha256', $to);
        $transport = $this->transport ?? static fn (string $recipient, string $mailSubject, string $body): bool => mail($recipient, $mailSubject, $body);
        $ok = $transport($to, $subject, $text);
        $this->logger->info('mailbox.smtp.sent', [
            'sent_to_hash' => $sentToHash,
            'subject' => $subject,
            'dsn_scheme' => parse_url($this->dsn, PHP_URL_SCHEME),
        ]);
        if (!$ok) {
            throw new \RuntimeException('mailbox smtp send failed');
        }
    }
}
