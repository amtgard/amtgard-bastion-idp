<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log\Sqlite;

use PDO;

/** Create log_entries schema in an hourly SQLite file (WAL). */
final class SqliteLogSchema
{
    public function open(string $path, LogPathResolver $paths): PDO
    {
        $paths->ensureDirectory($path);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->apply($pdo);

        return $pdo;
    }

    public function apply(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA synchronous=NORMAL;');
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS log_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts TEXT NOT NULL,
                ts_ms INTEGER,
                level INTEGER NOT NULL,
                level_name TEXT NOT NULL,
                channel TEXT NOT NULL,
                request_id TEXT,
                user_uuid TEXT,
                client_id TEXT,
                aud TEXT,
                message TEXT NOT NULL,
                context_json TEXT NOT NULL,
                extra_json TEXT,
                http_status INTEGER,
                route TEXT,
                duration_ms INTEGER,
                raw_line TEXT NOT NULL
            );
            SQL
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_ts ON log_entries(ts);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_request ON log_entries(request_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_user ON log_entries(user_uuid);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_client ON log_entries(client_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_level ON log_entries(level);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_channel ON log_entries(channel);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_status ON log_entries(http_status);');
    }
}
