<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Scan hourly SQLite files for rows matching request_id. */
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
        $traceRoot = $this->paths->root() . '/trace';
        if (! is_dir($traceRoot)) {
            return [];
        }

        $lines = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($traceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.logs.sqlite')) {
                continue;
            }

            $pdo = new PDO('sqlite:' . $file->getPathname());
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->schema->apply($pdo);
            $statement = $pdo->prepare(
                'SELECT raw_line FROM log_entries WHERE request_id = :request_id ORDER BY id ASC LIMIT :limit',
            );
            $statement->bindValue(':request_id', $requestId);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $lines[] = (string) $row['raw_line'];
                if (count($lines) >= $limit) {
                    return $lines;
                }
            }
        }

        return $lines;
    }
}
