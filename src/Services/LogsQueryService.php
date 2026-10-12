<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\Log\LogStream;
use Amtgard\IdP\Utility\Log\Sqlite\LogSearchCriteria;
use Amtgard\IdP\Utility\Log\Sqlite\LogSearchPage;
use Amtgard\IdP\Utility\Log\Sqlite\SqliteLogQuery;
use Monolog\Level;

/** Validates caps and builds SQLite searches for the log-read API. */
final class LogsQueryService
{
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly SqliteLogQuery $sqliteLogQuery,
        private readonly IdpLogging $idpLogging,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function searchTrace(array $query): LogSearchPage
    {
        return $this->search(LogStream::Trace, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function searchErrors(array $query): LogSearchPage
    {
        return $this->search(LogStream::Error, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function search(LogStream $stream, array $query): LogSearchPage
    {
        $limit = $this->resolveLimit($query);
        $criteria = $this->buildCriteria($stream, $query, $limit);

        try {
            $page = $this->sqliteLogQuery->search($criteria);
        } catch (\InvalidArgumentException $e) {
            $this->idpLogging->app()->error('logs query rejected', [
                'reason' => $e->getMessage(),
                'limit' => $limit,
            ]);

            throw $e;
        }

        $this->idpLogging->app()->info('logs query executed', [
            'stream' => $stream->value,
            'row_count' => count($page->entries),
            'limit' => $limit,
            'request_id_filter' => $criteria->requestId,
        ]);

        return $page;
    }

    public function traceByRequestId(string $requestId): LogSearchPage
    {
        return $this->search(LogStream::Trace, [
            'request_id' => $requestId,
            'limit' => self::MAX_LIMIT,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<array{level_name: string, channel: string, count: int}>
     */
    public function stats(array $query): array
    {
        $since = $this->optionalString($query, 'since');
        $until = $this->optionalString($query, 'until');

        return $this->sqliteLogQuery->stats(LogStream::Trace, $since, $until);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function analyze(array $body): LogSearchPage
    {
        $stream = (($body['stream'] ?? 'trace') === 'error') ? LogStream::Error : LogStream::Trace;

        return $this->search($stream, $body);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildCriteria(LogStream $stream, array $query, int $limit): LogSearchCriteria
    {
        $levelName = $this->optionalString($query, 'level');

        return new LogSearchCriteria(
            stream: $stream,
            limit: $limit,
            requestId: $this->optionalString($query, 'request_id'),
            userUuid: $this->optionalString($query, 'user_uuid'),
            clientId: $this->optionalString($query, 'client_id'),
            aud: $this->optionalString($query, 'aud'),
            channel: $this->optionalString($query, 'channel'),
            minLevel: $levelName !== null ? Level::fromName(strtoupper($levelName))->value : null,
            since: $this->optionalString($query, 'since'),
            until: $this->optionalString($query, 'until'),
            cursor: $this->optionalString($query, 'cursor'),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveLimit(array $query): int
    {
        $raw = $query['limit'] ?? self::MAX_LIMIT;
        if (! is_numeric($raw)) {
            $this->idpLogging->app()->error('logs query rejected', [
                'reason' => 'invalid_limit',
                'limit' => 0,
            ]);

            throw new \InvalidArgumentException('invalid_limit');
        }

        $limit = (int) $raw;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $this->idpLogging->app()->error('logs query rejected', [
                'reason' => 'over_limit',
                'limit' => $limit,
            ]);

            throw new \InvalidArgumentException('over_limit');
        }

        return $limit;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function optionalString(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
