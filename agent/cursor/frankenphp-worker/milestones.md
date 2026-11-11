# FrankenPHP worker mode — milestones

**Stack base:** `main`. **Planning:** `stack/frankenphp-worker`.

**Gates:** `composer test` (≥95% `src/` line coverage), Infection ≥80% MSI on touched code, logging tests for new branches, DI/route health tests.

Check milestones in order. One branch → one commit → next branch stacks on prior tip.

---

## Stacked branch table

| # | Branch | Single-commit goal |
|---|--------|-------------------|
| M0 | `stack/frankenphp-worker` | Preflight pack under `agent/cursor/frankenphp-worker/` |
| M1 | `stack/frankenphp-m1-prod-runtime` | Prod FrankenPHP image, Caddyfile, entrypoint, http worker + `WorkerRequestScope` |
| M2 | `stack/frankenphp-m2-dev-opt-in` | Optional dev FrankenPHP Dockerfile; compose + integ pin FPM |
| M3 | `stack/frankenphp-m3-closeout` | Caddy config test, README + deploy doc, full gates |

**Checklist (orchestrator):** [checklist.md](./checklist.md)

---

## M1 appendix (gates)

- **Branch:** `stack/frankenphp-m1-prod-runtime` @ `9a2fa6e`
- **Infection:** `bash scripts/mutation-test.sh --filter=WorkerRequestScope` — covered MSI ≥80%
- **Tests:** `WorkerRequestScopeTest`, existing container health tests unchanged
