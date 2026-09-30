<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;

final class OutboundMailFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private MailCarrierSettings $settings = new MailCarrierSettings(),
    ) {
    }

    public function forCarrier(MailCarrier $carrier): OutboundMail
    {
        return match ($carrier) {
            MailCarrier::Log => new LogOutboundMail($this->logger),
            MailCarrier::Php => new PhpOutboundMail($this->logger),
            MailCarrier::SendGrid => new SendGridOutboundMail(
                $this->logger,
                $this->settings->sendGridApiKey,
                $this->settings->sendGridFromEmail,
            ),
            MailCarrier::Ses => new SesOutboundMail(
                $this->logger,
                $this->settings->sesHost,
                $this->settings->sesUsername,
                $this->settings->sesPassword,
                $this->settings->sesFromEmail,
                $this->settings->sesPort,
            ),
        };
    }
}
