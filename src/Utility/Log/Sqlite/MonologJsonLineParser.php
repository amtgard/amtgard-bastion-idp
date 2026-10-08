<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

/** Decode one Monolog JSON spool line into insert columns. */
final class MonologJsonLineParser
{
    /**
     * @return array{
     *     ts: string,
     *     ts_ms: ?int,
     *     level: int,
     *     level_name: string,
     *     channel: string,
     *     request_id: ?string,
     *     user_uuid: ?string,
     *     client_id: ?string,
     *     aud: ?string,
     *     message: string,
     *     context_json: string,
     *     extra_json: ?string,
     *     http_status: ?int,
     *     route: ?string,
     *     duration_ms: ?int,
     *     raw_line: string
     * }|null
     */
    public function parse(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $context = $decoded['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        $extra = $decoded['extra'] ?? [];
        if (! is_array($extra)) {
            $extra = [];
        }

        $requestId = $this->stringOrNull($extra['request_id'] ?? $context['request_id'] ?? $decoded['request_id'] ?? null);
        $datetime = (string) ($decoded['datetime'] ?? $decoded['time'] ?? '');

        return [
            'ts' => $datetime,
            'ts_ms' => isset($decoded['ts_ms']) ? (int) $decoded['ts_ms'] : null,
            'level' => (int) ($decoded['level'] ?? 0),
            'level_name' => (string) ($decoded['level_name'] ?? $decoded['level'] ?? ''),
            'channel' => (string) ($decoded['channel'] ?? 'app'),
            'request_id' => $requestId,
            'user_uuid' => $this->stringOrNull($extra['user_uuid'] ?? $context['user_uuid'] ?? null),
            'client_id' => $this->stringOrNull($extra['client_id'] ?? $context['client_id'] ?? null),
            'aud' => $this->stringOrNull($extra['aud'] ?? $context['aud'] ?? null),
            'message' => (string) ($decoded['message'] ?? ''),
            'context_json' => json_encode($context, JSON_THROW_ON_ERROR),
            'extra_json' => $extra === [] ? null : json_encode($extra, JSON_THROW_ON_ERROR),
            'http_status' => isset($extra['http_status']) ? (int) $extra['http_status'] : null,
            'route' => $this->stringOrNull($extra['route'] ?? null),
            'duration_ms' => isset($extra['duration_ms']) ? (int) $extra['duration_ms'] : null,
            'raw_line' => $trimmed,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
