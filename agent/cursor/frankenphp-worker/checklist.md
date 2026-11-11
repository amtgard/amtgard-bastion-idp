# FrankenPHP worker mode — work checklist

**Stack base:** `main` (M0), then previous milestone tip.

**Gates:** `composer test` (≥95% line coverage on `src/`), Infection MSI ≥80% on touched PHP, log-tested decision branches on new code, existing container/route health tests pass.

**Do not push** unless the user asks.

**Resume from:** M3 complete — stack tip `stack/frankenphp-m3-closeout`.

---

## M0 — Preflight docs

- [x] `agent/cursor/frankenphp-worker/README.md` — scope, prod vs dev, gates
- [x] `implementation-plan.md`, `milestones.md`, `risks.md`, this checklist
- [x] One commit on `stack/frankenphp-worker`; check M0 boxes; note branch in orchestrator log

---

## M1 — Production FrankenPHP runtime

- [x] `docker/Dockerfile.prod` — FrankenPHP base, extensions, Composer, no nginx/fpm/memcached
- [x] `docker/Caddyfile`, `docker/frankenphp-entrypoint.sh`, `docker/php-frankenphp.ini` (or equivalent)
- [x] `config/http-app.php`; slim boot from `public/index.php` and `bin/http-worker.php`
- [x] `src/Utility/Http/WorkerRequestScope.php` + unit tests (session + `RequestLogContext` reset)
- [x] Log test if new decision branch (e.g. worker scope cleanup path) — N/A (unconditional cleanup)
- [x] Update [milestones.md](./milestones.md) M1 appendix: coverage + Infection
- [x] One commit on `stack/frankenphp-m1-prod-runtime`; check M1 boxes

---

## M2 — Dev opt-in

- [x] `docker/Dockerfile.frankenphp.dev` + `docker/Caddyfile.dev` (file watch)
- [x] `docker/compose.dev.yml` — `IDP_DEV_DOCKERFILE` interpolation
- [x] `.env.example` — document switch
- [x] `scripts/integ-up.sh`, `scripts/integ-down.sh`, integ-coverage paths pin FPM dev dockerfile
- [x] One commit on `stack/frankenphp-m2-dev-opt-in`; check M2 boxes

---

## M3 — Closeout

- [x] `tests/Docker/CaddyDocsRoutingConfigTest.php` (Docsify routing parity)
- [x] `README.md`, `docs/deploy-gb-host-env-vault.md` — FrankenPHP prod, optional dev
- [x] Full `composer test` + Infection on touched scope; checklist footer
- [x] One commit on `stack/frankenphp-m3-closeout`; check M3 boxes

---

## Orchestrator log

| Milestone | Branch | Commit | Status | Notes |
| --- | --- | --- | --- | --- |
| M0 | `stack/frankenphp-worker` | `8560d8c` | ok | Preflight pack |
| M1 | `stack/frankenphp-m1-prod-runtime` | `9a2fa6e` | ok | Prod image + worker loop |
| M2 | `stack/frankenphp-m2-dev-opt-in` | `f9c5fdc` | ok | Dev compose opt-in |
| M3 | `stack/frankenphp-m3-closeout` | `16732c8` | ok | Docs + Caddy test |

**M3 gates:** `composer test` OK (1052 tests); Infection on `WorkerRequestScope` ≥80% MSI; no new log branches. **Tip:** `stack/frankenphp-m3-closeout` @ `16732c8`.
