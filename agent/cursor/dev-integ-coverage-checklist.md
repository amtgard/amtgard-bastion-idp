# DEV integ — coverage orchestrator checklist

Plan: [dev-integ-coverage-plan.md](./dev-integ-coverage-plan.md).  
Stack from **`stack/dev-integ-14-client-iam`** (or `main` after merge). **Do not push** unless the user asks.

## Phase C — Isolation (required first)

| # | Branch | Done |
|---|--------|------|
| C0 | `stack/dev-integ-c0-integ-zero-warnings` | [x] |
| C1 | `stack/dev-integ-c1-integ-infra-compose` | [x] |
| C2 | `stack/dev-integ-c2-integ-wire-hosts` | [x] |
| C3 | `stack/dev-integ-c3-per-test-reseed` | [x] |
| C4 | `stack/dev-integ-c4-route-matrix-doc` | [ ] |

**Resume from:** C4 (per-test `IntegTestCase` reseed + random-order `composer integ`.)

## Phase D — Coverage expansion

| # | Branch | Done |
|---|--------|------|
| D1 | `stack/dev-integ-d1-static-docs` | [ ] |
| D2 | `stack/dev-integ-d2-auth-negatives` | [ ] |
| D3 | `stack/dev-integ-d3-connect-register` | [ ] |
| D4 | `stack/dev-integ-d4-oauth-errors` | [ ] |
| D5 | `stack/dev-integ-d5-oauth-scopes` | [ ] |
| D6 | `stack/dev-integ-d6-resources-clients-ui` | [ ] |
| D7 | `stack/dev-integ-d7-management-update` | [ ] |
| D8 | `stack/dev-integ-d8-client-iam-negatives` | [ ] |
| D9 | `stack/dev-integ-d9-low-latency-validate` | [ ] |
| D10 | `stack/dev-integ-d10-pvh-happy` | [ ] |
| D11 | `stack/dev-integ-d11-split-megatests` | [ ] |
| D12 | `stack/dev-integ-d12-junit-artifact` | [ ] |

## Harness (landed separately)

- [x] `integ.sh` / `composer integ` use **`--testdox`** for readable pass/fail titles.
