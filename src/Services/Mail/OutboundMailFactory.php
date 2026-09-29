<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;

final class OutboundMailFactory
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function forCarrier(MailCarrier $carrier): OutboundMail
    {
        return match ($carrier) {
            MailCarrier::Log => new LogOutboundMail($this->logger),
            MailCarrier::Php => new PhpOutboundMail($this->logger),
        };
    }
}
