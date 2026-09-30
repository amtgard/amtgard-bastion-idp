# Integration-driven line coverage (`src/`)

HTTP integration exercises code inside **php-fpm** in the integ web container (`:37080`). Unit coverage (`composer test` + host PHPUnit + `build/coverage-xml`) is unchanged.

## Approach

| Piece | Choice |
|-------|--------|
| Collector | **PCOV** in FPM (and jwt-worker CLI when coverage overlay is on) |
| Why not Xdebug | Dev image already ships Xdebug for debugging; coverage overlay sets `xdebug.mode=off` and enables PCOV for faster multi-request runs |
| Raw fragments | One JSON file per FPM request and one per worker process exit (`build/integ-coverage/raw/cov-*.json`) |
| Report | Host `php scripts/integ-coverage-merge.php` → `build/integ-coverage/html/index.html` + `build/integ-coverage/coverage.txt` |

Normal `./scripts/integ.sh` does **not** set `INTEG_COVERAGE=1` and does not mount `docker/pcov.integ.ini`.

## Commands

```bash
composer integ:coverage              # integ-up (coverage overlay) → composer integ → integ-down → merge
./scripts/integ-coverage.sh          # same
./scripts/integ-coverage.sh --keep   # leave DEV_INTEG stack up after merge
```

Requires Docker, same prerequisites as `./scripts/integ.sh`. Rebuilds the dev image on first run after `php8.4-pcov` was added to `docker/Dockerfile.dev`.

Open the HTML report:

```bash
open build/integ-coverage/html/index.html   # macOS
```

## Overlays

- `docker/compose.integ-coverage.yml` — FPM `99-integ-coverage.ini`, env, `build/integ-coverage` mount
- `docker/compose.worker.integ-coverage.yml` — jwt-worker PCOV + shared raw dir
- `scripts/integ-coverage-fpm-prepend.php` — `pcov\start()` + shutdown dump when `INTEG_COVERAGE_ENABLED=1`

`scripts/integ-up.sh` appends these compose files only when `INTEG_COVERAGE=1`.

## Blockers / limits

- **Worker granularity:** CLI worker emits a **single** merged dump when the process exits (integ-down or explicit `stop`), not per queue message.
- **FPM workers:** Each request in each pool worker produces a fragment; merge is union of executed lines.
- **Not for Infection:** This is a spike/reporting path; Infection still uses host unit `build/coverage-xml`.
- **PCOV vs Xdebug:** Both extensions remain installed; only one collects per SAPI via ini. If PCOV fails to load alongside Xdebug on a future PHP build, switch FPM ini to `xdebug.mode=coverage` and adapt the prepend script to `xdebug_get_code_coverage()`.
