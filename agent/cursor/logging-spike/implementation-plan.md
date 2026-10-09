# Logging spike — implementation plan

**Audience:** implementation agent.  
**Design:** [design-plan.md](./design-plan.md) · **Instrumentation catalog:** [instrumentation-checklist.md](./instrumentation-checklist.md)  
**Reference port:** Denarius `stack/denarius-log-l1-spool-sqlite-writer` (spool → hourly SQLite WAL → CLI query). IDP uses Monolog channels and the schema in design-plan § SQLite schema sketch.

**Stack base:** `feature/ork-flow-a-mail-via-ork` (operator IAM + Flow A ORK mail). Dev-integ **Mode A** (cookie-jar HTTP tests) and **Mode B** (Bearer/token) work described in [dev-integ-milestones.md](../dev-integ-milestones.md) is already on `main`; this logging stack does not depend on unpublished dev-integ branches.

**Gates (each milestone):** `composer test` (≥95% line coverage on `src/`), Infection `--min-msi=80 --min-covered-msi=80` when `src/` changes materially. Log decision branches with dedicated assertions per [development rules](~/.cursor/rules/development.mdc).

**Non-goals for Phase 0–1:** Log-read HTTP API, `IDP/AnalyzeLogs` JWT, dual trace/error SQLite files (use one hourly DB with `level_name` until ops chooses split), GDPR purge API, production log shipping agent.

---

## Stacked branches (serial, one commit each)

| # | Branch | Delivers |
|---|--------|----------|
| L0 | `stack/idp-log-l0-implementation-plan` | This document + README pointer |
| L1 | `stack/idp-log-l1-spool-sqlite-writer` | `LogPathResolver`, `SqliteLogSchema`, `MonologJsonLineParser`, `JsonLogSpoolHandler`, `LogSpoolDrainer`, `SqliteLogQuery`, `bin/log-sqlite-writer.php`, unit tests |
| L2 | `stack/idp-log-l2-idp-logging-wire` | `RequestLogContext`, `CorrelationMiddleware`, `CorrelationProcessor`, `RedactionProcessor`, `LogChannel` + `LogChannelFactory`, `IdpLogging`, container + middleware wiring, `.env.example`, optional `docker/compose.log-writer.yml` |
| L3 | `stack/idp-log-l3-idp-logs-cli` | `bin/idp-logs.php` (`query`, `bundle`, `drain-once`), CLI tests |
| L3b | `stack/idp-log-l3b-logging-integ` | `LoggingCorrelationIntegTest`, integ compose `LOG_*`, `CorrelationMiddleware` response header fix |
| L4 | `stack/idp-log-l4-m1-slice` | M1 checklist slice: `HttpObservabilityMiddleware` + instrument `/resources/validate` and `POST /oauth/token` per [instrumentation-checklist.md](./instrumentation-checklist.md) M1 |
| L5 | `stack/idp-log-l5-m2-auth-middleware` | M2 auth middleware deny paths |
| L6 | `stack/idp-log-l6-m3-jwt-pvh` | M3 JWT, PVH gate, Optional throws |
| L7 | `stack/idp-log-l7-m4-workers-pvh-queue` | M4 workers + PVH queue (`request_id` on enqueue) |
| L8 | `stack/idp-log-l8-m5-oauth-server` | M5 OAuth authorization server actions |
| L9 | `stack/idp-log-l9-m6-client-identity` | M6 client identity (login / social / connect) |
| L10 | `stack/idp-log-l10-m7-resource-apis` | M7 resource APIs, jsonError, 4xx/409 |
| L11 | `stack/idp-log-l11-m8-repositories` | M8 repositories, validators, silent fallbacks |
| L12 | `stack/idp-log-l12-m9-hardening` | M9 management, ORK, session infra |
| L13 | `stack/idp-log-l13-m10-log-read-api` | M10 log-read API / ORNs (new files) |

**Stack tip (2026-10-08):** `stack/idp-log-l13-m10-log-read-api` @ `a1ac506` — M2–M10 checklist complete; optional M1 platform rows still open in checklist (handlers / shared JSON helpers).

Do not push unless the user asks.

---

## L1 — Spool and SQLite writer (no HTTP behavior change)

- Hot path **must not** open SQLite. Only append JSONL to `LOG_ROOT/spool/active.jsonl` with `LOCK_EX` (fail silent).
- **`bin/log-sqlite-writer.php`:** sole process that opens `LOG_ROOT/trace/YYYY-MM-DD/HH.logs.sqlite` (WAL, batch insert, truncate spool after drain).
- **`LOG_ROOT`:** default `{project}/logs/bundles`; document “not on Google Drive sync path” in `.env.example`.
- Tests: spool → drain → query by `request_id` (temp dir).

## L2 — `IdpLogging` and correlation

- **`IdpLogging`:** single DI type; `app()`, `security()`, `oauth()`, `pvh()`, `audit()` return `LoggerInterface` for that channel.
- **`LoggerInterface`:** delegate to `$idpLog->app()` for legacy call sites.
- Handlers (per channel, `WhatFailureGroupHandler`):
  - `StreamHandler` → `php://stderr` with `JsonFormatter` (sync tail).
  - `JsonLogSpoolHandler` when `LOG_SPOOL_ENABLED=true` (default true in dev).
  - Keep `logs/app.log` stream until Phase 2 rollout (backward compat).
- Processors: correlation (`request_id` from `RequestLogContext`), redaction (design denylist keys).
- **`CorrelationMiddleware`:** accept `X-Request-Id` `/^[A-Za-z0-9_-]{8,64}$/` or generate 16 hex chars; add outermost (last `add()` in `middleware.php`).
- PHPUnit: redaction unit test; correlation middleware test; extend `ContainerResolutionOrderTest` tearDown to reset `LOG_*` env (same pattern as OAuth keys).

## L3 — Operator CLI

- **`bin/idp-logs.php`:** mirror Denarius `denarius-logs.php` — `query --request-id=`, `bundle --request-id=`, `drain-once`.

## L4 — M1 slice (Phase 0 exit)

- Reconstruct one staged request from SQLite using only `request_id` (document in milestone appendix).
- Kill sqlite-writer during traffic; HTTP must still succeed (manual/dev doc step).

## Phase 1+ (after L4)

- Separate error-stream SQLite or `stream` column + retention jobs (design open questions).
- Log-read API + ORNs (M10 / design § log-read API).
- Instrumentation sweep M2–M9 (295 sites); prefer `IdpLogging` channel selectors over raw `LoggerInterface` in new code.

---

## Environment variables (L2+)

| Variable | Default | Purpose |
|----------|---------|---------|
| `LOG_ROOT` | `{project}/logs/bundles` | Spool + SQLite root |
| `LOG_SPOOL_ENABLED` | `true` | Append spool handler |
| `LOG_TRACE_LEVEL` | `debug` if `APP_DEBUG`, else `notice` | Trace handler floor |
| `LOG_STDERR_ENABLED` | `true` | Sync stderr JSONL |
| `LOG_WRITER_SLEEP_MS` | `200` | Writer loop sleep |

---

## Milestone log

Append each completed branch to [milestones.md](./milestones.md) (create on first L1 commit): branch name, coverage %, Infection covered MSI, log-tested branches.
