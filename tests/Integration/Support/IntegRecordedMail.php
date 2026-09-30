<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration\Support;

use Amtgard\IdP\Services\Mail\IntegRecordingOutboundMail;

/** Read mailbox codes captured by {@see IntegRecordingOutboundMail} in the integ stack. */
final class IntegRecordedMail
{
    private const DEFAULT_SESSIONS_CONTAINER = 'amtgard-idp-sessions-integ';

    /**
     * @return array{code: ?string, subject: string, text: string}|null
     */
    public static function lastDeliveryForEmail(string $email): ?array
    {
        if (!self::dockerAvailable()) {
            return null;
        }

        $container = (string) (getenv('INTEG_SESSIONS_CONTAINER') ?: self::DEFAULT_SESSIONS_CONTAINER);
        $redisDb = (string) (getenv('REDIS_PUBSUB_DB') ?: '0');
        $key = IntegRecordingOutboundMail::redisKeyForEmail($email);
        $command = sprintf(
            'docker exec %s redis-cli -n %s GET %s 2>&1',
            escapeshellarg($container),
            escapeshellarg($redisDb),
            escapeshellarg($key),
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Integ recorded mail GET failed: ' . implode("\n", $output));
        }

        $line = trim(implode("\n", $output));
        if ($line === '' || strcasecmp($line, '(nil)') === 0) {
            return null;
        }

        /** @var array{code?: ?string, subject?: string, text?: string} $decoded */
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        return [
            'code' => isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null,
            'subject' => (string) ($decoded['subject'] ?? ''),
            'text' => (string) ($decoded['text'] ?? ''),
        ];
    }

    public static function waitForCode(string $email, int $attempts = 10, int $sleepMicros = 100_000): string
    {
        for ($i = 0; $i < $attempts; ++$i) {
            $delivery = self::lastDeliveryForEmail($email);
            if ($delivery !== null && $delivery['code'] !== null && $delivery['code'] !== '') {
                return $delivery['code'];
            }
            usleep($sleepMicros);
        }

        throw new \RuntimeException('No recorded mailbox code for ' . $email);
    }

    public static function extractMagicLinkPath(string $text, string $idpBaseUrl): ?string
    {
        $base = rtrim($idpBaseUrl, '/');
        $pattern = '#(' . preg_quote($base, '#') . '/resources/profile/link-ork/magic\?t=[^\s]+)#';
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $url = $matches[1];

        return (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY);
    }

    private static function dockerAvailable(): bool
    {
        $output = [];
        $exitCode = 0;
        exec('docker info 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }
}
