<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;
use Redis;

/**
 * DEV_INTEG mail carrier: stores the last delivery per destination in pub/sub Redis
 * so integration tests can read the verification code (never log the raw code).
 */
final class IntegRecordingOutboundMail implements OutboundMail
{
    public const KEY_PREFIX = 'integ:mailbox:delivery:';

    public const TTL_SECONDS = 900;

    public function __construct(
        private Redis $redis,
        private LoggerInterface $logger,
    ) {
    }

    public static function redisKeyForEmail(string $email): string
    {
        return self::KEY_PREFIX . hash('sha256', strtolower(trim($email)));
    }

    public function send(string $to, string $subject, string $text): void
    {
        $code = null;
        if (preg_match('/Your Amtgard verification code is (\d{6})\./', $text, $matches) === 1) {
            $code = $matches[1];
        }

        $payload = json_encode([
            'code' => $code,
            'subject' => $subject,
            'text' => $text,
        ], JSON_THROW_ON_ERROR);

        $this->redis->setex(self::redisKeyForEmail($to), self::TTL_SECONDS, $payload);

        $this->logger->info('mail.sent', [
            'carrier' => 'integ-recording',
            'sent_to_hash' => hash('sha256', $to),
            'subject' => $subject,
        ]);
    }
}
