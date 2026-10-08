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

## L3b — logging HTTP integ

- Branch: `stack/idp-log-l3b-logging-integ`
- `LoggingCorrelationIntegTest` (Mode B): `GET /version` echoes valid `X-Request-Id` or returns 16-char hex when absent.
- Fix: `CorrelationMiddleware` sets response header from resolved id after context teardown (L2 bug found by integ).
- Integ overlay: `LOG_SPOOL_ENABLED=true`, `LOG_ROOT=/var/www/idp.amtgard.com/logs/bundles-integ`.
- Spool drain from integ is host-side: after traffic with a known request id, operators run `php bin/idp-logs.php drain-once` (CLI covered in L3 unit tests).
- Gates: `composer test`; `./scripts/integ.sh`.

## L4 — M1 slice (Phase 0 exit)

- Branch: `stack/idp-log-l4-m1-slice`
- `HttpObservabilityMiddleware` (one summary line per request; channel by path/status).
- `/resources/validate` reject paths log at **error**; success paths at **info**.
- `OAuthTokenAction` logs `oauth token rejected` on League `OAuthServerException`.
- Integ: extend `LoggingCorrelationIntegTest` for `/resources/validate` 401 and `/oauth/token` 400 with `X-Request-Id`.
- Gates: `composer test`; `./scripts/integ.sh` (logging integ green; stack may retain unrelated ORK/mailbox integ failures until ORK deploy).
- Phase 0 exit: reconstruct one request from SQLite by `request_id` (operator: traffic → `bin/idp-logs.php drain-once` → `bundle --request-id=`).
