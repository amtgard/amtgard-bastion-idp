# Logging spike — milestones

Stack base: `feature/ork-flow-a-mail-via-ork`. See [implementation-plan.md](./implementation-plan.md).

## L1 — spool and SQLite writer

- Branch: `stack/idp-log-l1-spool-sqlite-writer`
- Hourly SQLite WAL under `LOG_ROOT`, JSONL spool, `bin/log-sqlite-writer.php`, query helper `SqliteLogQuery`.
- Gates: `composer test` (SqliteLogBundleTest green).

## L2 — IdpLogging wire

- Branch: `stack/idp-log-l2-idp-logging-wire`
- `IdpLogging`, `LogChannelFactory`, correlation/redaction processors, `CorrelationMiddleware`, container + `.env.example`, optional `docker/compose.log-writer.yml`.
- Gates: `composer test` OK.

## L3 — idp-logs CLI

- Branch: `stack/idp-log-l3-idp-logs-cli`
- `bin/idp-logs.php` (`query`, `bundle`, `drain-once`).
