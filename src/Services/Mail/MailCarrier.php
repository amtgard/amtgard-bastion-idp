<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

enum MailCarrier: string
{
    case Log = 'log';
    case Php = 'php';

    public static function fromEnv(): self
    {
        $raw = strtolower(trim((string) ($_ENV['MAIL_TRANSPORT'] ?? '')));
        if ($raw === '') {
            return self::Log;
        }

        return self::tryFrom($raw) ?? throw new \InvalidArgumentException(sprintf(
            'Mail carrier "%s" is not registered. Known carriers: %s.',
            $raw,
            implode(', ', array_map(static fn (self $carrier): string => $carrier->value, self::cases())),
        ));
    }
}
