# FrankenPHP worker mode (IDP)

Replace in-container **nginx + php-fpm** in production web slots with **FrankenPHP worker mode** (Caddy + long-lived PHP workers). Host nginx and generic-green-blue stay unchanged (`host/nginx.*.conf` → slot ports **37080** / **37081**).

**Production:** always FrankenPHP (`docker/Dockerfile.prod`).

**Development:** default remains **php-fpm + nginx** (`docker/Dockerfile.dev`). Opt in via `IDP_DEV_DOCKERFILE=docker/Dockerfile.frankenphp.dev` in `.env` (Compose substitution).

**Out of scope:** JWT PVH worker (`bin/jwt-pvh-worker.php`) — still plain `php` CLI, tagged from slot image in `scripts/gb-after-slot-up.sh`.

**Non-goals:** removing host nginx; FrankenPHP for integ/PCOV (integ pins FPM dev image); runtime toggle to disable FrankenPHP in prod compose.

**Deliverables:** [implementation-plan.md](./implementation-plan.md) · [milestones.md](./milestones.md) · [checklist.md](./checklist.md) · [risks.md](./risks.md)

**Stack base:** `main`. **Planning branch:** `stack/frankenphp-worker` (docs). First implementation branch: `stack/frankenphp-m1-prod-runtime`.

**Resume:** orchestrator reads [checklist.md](./checklist.md); start at first unchecked milestone.

## Gates (IDP PHP milestones)

`composer test` (≥95% line coverage on `src/`), Infection MSI ≥80% on touched PHP, log tests for new decision branches, existing container/route health tests pass. Do not push unless the user asks.
