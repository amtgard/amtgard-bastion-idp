# FrankenPHP worker mode — implementation plan

**Companion:** [README.md](./README.md) · [milestones.md](./milestones.md) · [risks.md](./risks.md)

## Architecture

```mermaid
flowchart LR
  browser[Browser]
  hostNginx[Host nginx]
  caddy[FrankenPHP port 80]
  loop[Worker loop]
  slim[Slim booted once]
  browser --> hostNginx --> caddy --> loop --> slim
```

| Layer | Today | Target |
|-------|--------|--------|
| Host | TLS + blue/green proxy | Unchanged |
| Slot container | nginx → php-fpm sock | FrankenPHP (`frankenphp run`) |
| App bootstrap | `public/index.php` per request | `bin/http-worker.php` boots once; `frankenphp_handle_request` per HTTP request |
| Dev slot | `Dockerfile.dev` + bind mount | Same default; optional `Dockerfile.frankenphp.dev` |

## Production image

- Base: pinned `dunglas/frankenphp` PHP **8.4**, build gate **≥ 8.4.27** (CVE-2026-109618/619).
- Extensions: `pdo_mysql`, `redis`, `gd`, `zip`, `opcache`, `mbstring`, `curl`, `xml`.
- Drop: in-container nginx, php-fpm, memcached (`heartbeat.sh` only started memcached; no `src/` usage).
- [docker/Caddyfile](./../../../docker/Caddyfile) (prod): `:80`, `root` `public/`, Docsify `/docs` routing parity with [docker/nginx.idp.config](./../../../docker/nginx.idp.config), static image cache headers, `worker` block with `FRANKENPHP_NUM` (default 2), `max_requests` 500, **no** file watch.
- PHP: `variables_order=EGPCS`, `memory_limit=128M` per worker, `session.upload_progress.enabled=0`, opcache `validate_timestamps=0` prod.
- Entrypoint: ensure `logs/`, `cache/twig`, `cache/di`, `frankenphp run` as PID 1.
- [docker/compose.prod.yml](./../../../docker/compose.prod.yml): still `dockerfile: docker/Dockerfile.prod` only.

## Application worker loop

- Extract Slim boot to `config/http-app.php`; `public/index.php` and `bin/http-worker.php` both use it.
- Worker: boot app once, loop `frankenphp_handle_request(fn () => $app->run())`.
- **WorkerRequestScope** (Facade / cleanup): `finally` after each request — `session_write_close()`, clear `$_SESSION`, `RequestLogContext::reset()`, drain output buffers. Unit-tested without FrankenPHP ext.
- Keep PHP-DI singletons and one PDO/Redis per worker; rely on `max_requests` for leak bounds.
- `index.php` unchanged path for `composer start` and FPM dev.

## Dev opt-in

- `docker/compose.dev.yml`: `dockerfile: ${IDP_DEV_DOCKERFILE:-docker/Dockerfile.dev}`.
- `docker/Dockerfile.frankenphp.dev`: FrankenPHP + dev Caddyfile with **worker file watch** on bind-mounted tree; no Xdebug.
- `.env.example`: document `IDP_DEV_DOCKERFILE`.
- `scripts/integ-up.sh`, `scripts/integ-down.sh`, integ-coverage compose paths: export `IDP_DEV_DOCKERFILE=docker/Dockerfile.dev`.

## Verification

| Check | Where |
|-------|--------|
| `WorkerRequestScope::finish()` | Unit test |
| `/docs` Caddy rewrites | `tests/Docker/CaddyDocsRoutingConfigTest.php` |
| Container health (manual) | `curl` `/version`, `scripts/gb-health-developer-docs.sh` |
| JWT worker CLI | `php bin/jwt-pvh-worker.php` in prod image |

## Docs

- [README.md](./../../../README.md) — runtime description prod vs dev.
- [docs/deploy-gb-host-env-vault.md](./../../../docs/deploy-gb-host-env-vault.md) — slot process model.
