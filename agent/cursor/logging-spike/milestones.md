# Logging spike — milestones

Stack base: `feature/ork-flow-a-mail-via-ork`. See [implementation-plan.md](./implementation-plan.md).

## L1 — spool and SQLite writer

- Branch: `stack/idp-log-l1-spool-sqlite-writer`
- Hourly SQLite WAL under `LOG_ROOT`, JSONL spool, `bin/log-sqlite-writer.php`, query helper `SqliteLogQuery`.
- Gates: `composer test` (SqliteLogBundleTest green).
