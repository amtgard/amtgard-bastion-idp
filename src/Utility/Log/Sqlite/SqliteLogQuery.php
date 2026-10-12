<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use Amtgard\IdP\Utility\Log\LogStream;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Scan hourly SQLite files for structured rows and raw JSONL lines. */
final class SqliteLogQuery
{
    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly SqliteLogSchema $schema,
    ) {
    }

    /**
     * @return list<string> raw JSON lines, oldest first within each file
     */
    public function rawLinesForRequestId(string $requestId, int $limit = 5000): array
    {
        $entries = $this->entriesForRequestId($requestId, LogStream::Trace, $limit);

        return array_map(
            static fn (array $row): string => (string) ($row['raw_line'] ?? ''),
            $entries,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entriesForRequestId(string $requestId, LogStream $stream, int $limit = 500): array
    {
        $criteria = new LogSearchCriteria(
            stream: $stream,
            limit: $limit,
            requestId: $requestId,
        );

        return $this->search($criteria)->entries;
    }

    public function search(LogSearchCriteria $criteria): LogSearchPage
    {
        $cursor = $this->decodeCursor($criteria->cursor);
        $streamRoot = $this->streamRoot($criteria->stream);
        if (! is_dir($streamRoot)) {
            return new LogSearchPage([], null);
        }
        $files = $this->listSqliteFiles($streamRoot);
        $entries = [];
        $nextCursor = null;
        $remaining = $criteria->limit;

        foreach ($files as $filePath) {
            if ($remaining <= 0) {
                break;
            }

            if ($cursor !== null && $this->isFileBeforeCursor($filePath, $cursor)) {
                continue;
            }

            $page = $this->queryFile($filePath, $criteria, $cursor, $remaining);
            foreach ($page['entries'] as $entry) {
                $entries[] = $entry;
                --$remaining;
                if ($remaining <= 0) {
                    $nextCursor = $this->encodeCursor($filePath, (int) $entry['id']);
                    break 2;
                }
            }

            if ($page['has_more_in_file']) {
                $last = $page['entries'] !== [] ? $page['entries'][array_key_last($page['entries'])] : null;
                if ($last !== null) {
                    $nextCursor = $this->encodeCursor($filePath, (int) $last['id']);
                }
                break;
            }

            $cursor = null;
        }

        return new LogSearchPage($entries, $nextCursor);
    }

    /**
     * @return list<array{level_name: string, channel: string, count: int}>
     */
    public function stats(LogStream $stream, ?string $since, ?string $until): array
    {
        $criteria = new LogSearchCriteria(
            stream: $stream,
            limit: 10_000,
            since: $since,
            until: $until,
        );
        $page = $this->search($criteria);
        $counts = [];
        foreach ($page->entries as $entry) {
            $key = ($entry['level_name'] ?? '') . '|' . ($entry['channel'] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            [$levelName, $channel] = explode('|', $key, 2);
            $rows[] = [
                'level_name' => $levelName,
                'channel' => $channel,
                'count' => $count,
            ];
        }

        return $rows;
    }

    public function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($json === false) {
            throw new \InvalidArgumentException('invalid_cursor');
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! isset($decoded['file'], $decoded['id'])) {
            throw new \InvalidArgumentException('invalid_cursor');
        }

        return [
            'file' => (string) $decoded['file'],
            'id' => (int) $decoded['id'],
        ];
    }

    public function encodeCursor(string $filePath, int $id): string
    {
        $payload = json_encode(['file' => $filePath, 'id' => $id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private function streamRoot(LogStream $stream): string
    {
        return $this->paths->root() . '/' . $stream->value;
    }

    /**
     * @return list<string> newest hourly files first
     */
    private function listSqliteFiles(string $streamRoot): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($streamRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.logs.sqlite')) {
                $paths[] = $file->getPathname();
            }
        }

        rsort($paths);

        return $paths;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, has_more_in_file: bool}
     */
    private function queryFile(
        string $filePath,
        LogSearchCriteria $criteria,
        ?array $cursor,
        int $limit,
    ): array {
        $pdo = new PDO('sqlite:' . $filePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->schema->apply($pdo);

        $sql = 'SELECT * FROM log_entries WHERE 1=1';
        $params = [];

        if ($cursor !== null && $cursor['file'] === $filePath) {
            $sql .= ' AND id > :cursor_id';
            $params['cursor_id'] = $cursor['id'];
        }

        if ($criteria->requestId !== null) {
            $sql .= ' AND request_id = :request_id';
            $params['request_id'] = $criteria->requestId;
        }
        if ($criteria->userUuid !== null) {
            $sql .= ' AND user_uuid = :user_uuid';
            $params['user_uuid'] = $criteria->userUuid;
        }
        if ($criteria->clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $criteria->clientId;
        }
        if ($criteria->aud !== null) {
            $sql .= ' AND aud = :aud';
            $params['aud'] = $criteria->aud;
        }
        if ($criteria->channel !== null) {
            $sql .= ' AND channel = :channel';
            $params['channel'] = $criteria->channel;
        }
        if ($criteria->minLevel !== null) {
            $sql .= ' AND level >= :min_level';
            $params['min_level'] = $criteria->minLevel;
        }
        if ($criteria->since !== null) {
            $sql .= ' AND ts >= :since';
            $params['since'] = $criteria->since;
        }
        if ($criteria->until !== null) {
            $sql .= ' AND ts <= :until';
            $params['until'] = $criteria->until;
        }

        $sql .= ' ORDER BY id ASC LIMIT :limit';
        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        $entries = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($entries) >= $limit) {
                return [
                    'entries' => $entries,
                    'has_more_in_file' => true,
                ];
            }
            $entries[] = $this->normalizeRow($row);
        }

        return [
            'entries' => $entries,
            'has_more_in_file' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        $extra = json_decode((string) ($row['extra_json'] ?? 'null'), true);

        return [
            'id' => (int) $row['id'],
            'ts' => (string) $row['ts'],
            'ts_ms' => isset($row['ts_ms']) ? (int) $row['ts_ms'] : null,
            'level' => (int) $row['level'],
            'level_name' => (string) $row['level_name'],
            'channel' => (string) $row['channel'],
            'request_id' => $row['request_id'] !== null ? (string) $row['request_id'] : null,
            'user_uuid' => $row['user_uuid'] !== null ? (string) $row['user_uuid'] : null,
            'client_id' => $row['client_id'] !== null ? (string) $row['client_id'] : null,
            'aud' => $row['aud'] !== null ? (string) $row['aud'] : null,
            'message' => (string) $row['message'],
            'context' => is_array($context) ? $context : [],
            'extra' => is_array($extra) ? $extra : null,
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
            'route' => $row['route'] !== null ? (string) $row['route'] : null,
            'duration_ms' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            'raw_line' => (string) ($row['raw_line'] ?? ''),
        ];
    }

    /**
     * @param array{file: string, id: int} $cursor
     */
    private function isFileBeforeCursor(string $filePath, array $cursor): bool
    {
        return $filePath < $cursor['file'];
    }
}
