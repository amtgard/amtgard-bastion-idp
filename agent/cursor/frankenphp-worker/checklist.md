# FrankenPHP worker mode — work checklist

**Stack base:** `main` (M0), then previous milestone tip.

**Gates:** `composer test` (≥95% line coverage on `src/`), Infection MSI ≥80% on touched PHP, log-tested decision branches on new code, existing container/route health tests pass.

**Do not push** unless the user asks.

**Resume from:** first unchecked milestone below.

---

## M0 — Preflight docs

- [x] `agent/cursor/frankenphp-worker/README.md` — scope, prod vs dev, gates
- [x] `implementation-plan.md`, `milestones.md`, `risks.md`, this checklist
- [x] One commit on `stack/frankenphp-worker`; check M0 boxes; note branch in orchestrator log

---

## M1 — Production FrankenPHP runtime

- [ ] `docker/Dockerfile.prod` — FrankenPHP base, extensions, Composer, no nginx/fpm/memcached
- [ ] `docker/Caddyfile`, `docker/frankenphp-entrypoint.sh`, `docker/php-frankenphp.ini` (or equivalent)
- [ ] `config/http-app.php`; slim boot from `public/index.php` and `bin/http-worker.php`
- [ ] `src/Utility/Http/WorkerRequestScope.php` + unit tests (session + `RequestLogContext` reset)
- [ ] Log test if new decision branch (e.g. worker scope cleanup path)
- [ ] Update [milestones.md](./milestones.md) M1 appendix: coverage + Infection
- [ ] One commit on `stack/frankenphp-m1-prod-runtime`; check M1 boxes

---

## M2 — Dev opt-in

- [ ] `docker/Dockerfile.frankenphp.dev` + `docker/Caddyfile.dev` (file watch)
- [ ] `docker/compose.dev.yml` — `IDP_DEV_DOCKERFILE` interpolation
- [ ] `.env.example` — document switch
- [ ] `scripts/integ-up.sh`, `scripts/integ-down.sh`, integ-coverage paths pin FPM dev dockerfile
- [ ] One commit on `stack/frankenphp-m2-dev-opt-in`; check M2 boxes

---

## M3 — Closeout

- [ ] `tests/Docker/CaddyDocsRoutingConfigTest.php` (Docsify routing parity)
- [ ] `README.md`, `docs/deploy-gb-host-env-vault.md` — FrankenPHP prod, optional dev
- [ ] Full `composer test` + Infection on touched scope; checklist footer
- [ ] One commit on `stack/frankenphp-m3-closeout`; check M3 boxes

---

## Orchestrator log

| Milestone | Branch | Commit | Status | Notes |
| --- | --- | --- | --- | --- |
| M0 | `stack/frankenphp-worker` | `97ac540` | ok | Preflight pack only |
| M1 | | | | |
| M2 | | | | |
| M3 | | | | |
