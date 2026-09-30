<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

final class MailCarrierSettings
{
    public function __construct(
        public readonly string $sendGridApiKey = '',
        public readonly string $sendGridFromEmail = '',
        public readonly string $sesHost = '',
        public readonly string $sesUsername = '',
        public readonly string $sesPassword = '',
        public readonly string $sesFromEmail = '',
        public readonly int $sesPort = 587,
    ) {
    }

    public static function fromEnv(): self
    {
        $port = trim((string) ($_ENV['AMAZON_SES_PORT'] ?? ''));

        return new self(
            trim((string) ($_ENV['SENDGRID_API_KEY'] ?? '')),
            trim((string) ($_ENV['SENDGRID_FROM_EMAIL'] ?? '')),
            trim((string) ($_ENV['AMAZON_SES_HOST'] ?? '')),
            trim((string) ($_ENV['AMAZON_SES_USERNAME'] ?? '')),
            (string) ($_ENV['AMAZON_SES_PASSWORD'] ?? ''),
            trim((string) ($_ENV['AMAZON_SES_FROM_EMAIL'] ?? '')),
            $port === '' ? 587 : (int) $port,
        );
    }
}
