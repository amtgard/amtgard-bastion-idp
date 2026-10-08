<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use DateTimeImmutable;
use PDO;
use PDOStatement;

/** Move spool JSONL lines into hourly SQLite WAL files. */
final class LogSpoolDrainer
{
    public function __construct(
        private readonly LogPathResolver $paths,
        private readonly SqliteLogSchema $schema,
        private readonly MonologJsonLineParser $parser,
    ) {
    }

    /** @return int Number of lines persisted. */
    public function drainOnce(): int
    {
        $spool = $this->paths->spoolFile();
        if (! is_file($spool)) {
            return 0;
        }

        $lines = $this->readSpoolLines($spool);
        if ($lines === []) {
            $this->truncateSpool($spool);

            return 0;
        }

        $inserted = 0;
        $pdo = null;
        $statement = null;
        $currentPath = '';

        foreach ($lines as $line) {
            $row = $this->parser->parse($line);
            if ($row === null) {
                continue;
            }

            $instant = $this->instantFromRow($row['ts']);
            $sqlitePath = $this->paths->sqlitePathForInstant($instant);
            if ($sqlitePath !== $currentPath) {
                $pdo = $this->schema->open($sqlitePath, $this->paths);
                $statement = $this->prepareInsert($pdo);
                $currentPath = $sqlitePath;
            }

            $this->bindInsert($statement, $row);
            $statement->execute();
            ++$inserted;
        }

        $this->truncateSpool($spool);

        return $inserted;
    }

    /**
     * @return list<string>
     */
    private function readSpoolLines(string $spool): array
    {
        $handle = fopen($spool, 'cb+');
        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return [];
            }

            $contents = stream_get_contents($handle);
            if ($contents === false || $contents === '') {
                return [];
            }

            return array_values(array_filter(
                explode("\n", $contents),
                static fn (string $line): bool => trim($line) !== '',
            ));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function truncateSpool(string $spool): void
    {
        $handle = fopen($spool, 'cb+');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function instantFromRow(string $ts): DateTimeImmutable
    {
        if ($ts === '') {
            return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        try {
            return new DateTimeImmutable($ts);
        } catch (\Exception) {
            return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    private function prepareInsert(PDO $pdo): PDOStatement
    {
        return $pdo->prepare(
            'INSERT INTO log_entries (
                ts, ts_ms, level, level_name, channel, request_id, user_uuid, client_id, aud,
                message, context_json, extra_json, http_status, route, duration_ms, raw_line
            ) VALUES (
                :ts, :ts_ms, :level, :level_name, :channel, :request_id, :user_uuid, :client_id, :aud,
                :message, :context_json, :extra_json, :http_status, :route, :duration_ms, :raw_line
            )',
        );
    }

    /**
     * @param array{
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
     * } $row
     */
    private function bindInsert(PDOStatement $statement, array $row): void
    {
        $statement->bindValue(':ts', $row['ts']);
        $statement->bindValue(':ts_ms', $row['ts_ms'], $row['ts_ms'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':level', $row['level'], PDO::PARAM_INT);
        $statement->bindValue(':level_name', $row['level_name']);
        $statement->bindValue(':channel', $row['channel']);
        $statement->bindValue(':request_id', $row['request_id']);
        $statement->bindValue(':user_uuid', $row['user_uuid']);
        $statement->bindValue(':client_id', $row['client_id']);
        $statement->bindValue(':aud', $row['aud']);
        $statement->bindValue(':message', $row['message']);
        $statement->bindValue(':context_json', $row['context_json']);
        $statement->bindValue(':extra_json', $row['extra_json']);
        $statement->bindValue(':http_status', $row['http_status'], $row['http_status'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':route', $row['route']);
        $statement->bindValue(':duration_ms', $row['duration_ms'], $row['duration_ms'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':raw_line', $row['raw_line']);
    }
}
