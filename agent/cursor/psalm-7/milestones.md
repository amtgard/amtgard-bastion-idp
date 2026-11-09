# Psalm 7 — milestones

**Stack base:** `main` @ `d2bf400` (docs/nginx merged). **Planning branch:** `stack/psalm-7` (docs only).

**Resume:** After M0 commit, checkout `stack/psalm-7-m1-deps` from `stack/psalm-7` tip.

**Companion:** [README.md](./README.md) · [implementation-plan.md](./implementation-plan.md) · [risks.md](./risks.md)

Check milestones in order. One branch → one commit → stack next branch from prior tip.

---

## Stacked branch table

| # | Branch | Single-commit goal |
|---|--------|-------------------|
| M0 | `stack/psalm-7` | Planning pack under `agent/cursor/psalm-7/` |
| M1 | `stack/psalm-7-m1-deps` | Add Psalm 7 + PHPUnit plugin dev-deps; lockfile only |
| M2 | `stack/psalm-7-m2-config` | `psalm.xml`, `composer psalm` scripts, `src/` scope, taint on |
| M3 | `stack/psalm-7-m3-baseline` | First green `composer psalm` via committed baseline |
| M4 | `stack/psalm-7-m4-taint-stubs` | PSR-7 gaps, `RedirectValidator`, Twig/sink annotations |
| M5 | `stack/psalm-7-m5-dual-analyse` | `composer analyse`, docs in README; optional baseline shrink |
| M6 | `stack/psalm-7-m6-ci` | CI recipe: `--init-ci`, SARIF, Code Scanning (wire or doc-only) |

---

## M0 — Planning

**Branch:** `stack/psalm-7`

- [x] `agent/cursor/psalm-7/README.md` — scope, non-goals, Psalm 7 article link
- [x] `implementation-plan.md` — architecture, PHPStan coexistence, Slim taint, CI, baseline
- [x] `milestones.md` — this file
- [x] `risks.md` — false positives, vendor, dual analyzer cost
- [x] No `vimeo/psalm` in `composer.json` on this branch

**Gates:** N/A (docs). Parent agent may merge plan to `main` separately.

**Exit:** Implementer starts M1 from this tip.

---

## M1 — Dependencies

**Branch:** `stack/psalm-7-m1-deps`

- [x] `composer require --dev vimeo/psalm:^7.0 psalm/plugin-phpunit:^0.22` (`^1.0` not on Packagist yet; lock pins `7.2.0` / `0.22.0`)
- [x] Confirm PHP 8.4 satisfies Psalm 7 runner requirements
- [x] No `psalm.xml` yet (or empty stub) — must not break CI expectations
- [x] Update [milestones.md](./milestones.md) appendix: Psalm version from lock

**Gates:** `composer test`; `composer mutate`; `composer cs`; `composer stan` (unchanged behavior).

**Exit:** `vendor/bin/psalm --version` works.

---

## M2 — Configuration

**Branch:** `stack/psalm-7-m2-config`

- [x] Add `psalm.xml`: `projectFiles` = `src/`; `runTaintAnalysis="true"`; `loadLibraryStubs="true"`; `phpVersion="8.4"`
- [x] Register `psalm/plugin-phpunit` when ready to analyse tests later (may leave unused in M2)
- [x] Composer scripts: `psalm`, `psalm:ci`, `psalm:security` (SARIF → `build/`, gitignored)
- [x] Document intentional **scope mismatch**: PHPStan `src` + `tests`, Psalm `src` only
- [x] First local run allowed to fail (no baseline yet) — do not commit red run as gate

**Gates:** `composer test`; `composer mutate`; `composer stan`; config validates (`vendor/bin/psalm --show-info=false` exits non-zero OK).

**Exit:** Config committed; team can reproduce failing scan locally.

---

## M3 — Baseline

**Branch:** `stack/psalm-7-m3-baseline`

- [x] Run `vendor/bin/psalm --set-baseline=psalm-baseline.xml` on `src/`
- [x] Record baseline stats in milestone appendix (total issues, SECURITY count)
- [x] Add `composer psalm` to “full gate” list in root README or agent doc if appropriate
- [x] Enable `findUnusedBaselineEntry="true"` when supported
- [x] Decision: single baseline vs split taint baseline (default: **single** unless SECURITY > 50% of entries)

**Gates:** `composer test`; `composer mutate`; `composer stan`; **`composer psalm` exit 0**.

**Exit:** Green Psalm on default developer workflow.

---

## M4 — Taint stubs and app annotations

**Branch:** `stack/psalm-7-m4-taint-stubs`

- [x] Verify built-in PSR-7 stubs cover Slim request usage; add `stubs/` only for gaps
- [x] Annotate `RedirectValidator::sanitize*` with taint escape + flow (see implementation plan)
- [x] Review `ScriptAlertResponse`, `BaseAuthController` Location headers, OAuth redirects for real issues vs baseline noise — fix real, suppress with comment only if intentional
- [x] Twig: confirm `render()` sink behavior; auto-escaped templates vs raw context keys
- [x] Shrink baseline where annotations fix SECURITY without behavior change
- [x] Cross-reference new SECURITY findings to [security-review-owasp-cisa.md](../security-review-owasp-cisa.md) (tag AGREE items only in notes)

**Gates:** `composer test`; `composer mutate`; `composer stan`; `composer psalm`; no regression in baseline count without justification in commit message.

**Exit:** Documented app taint model; measurably fewer false open-redirect / HTML paths.

---

## M5 — Dual analyse workflow

**Branch:** `stack/psalm-7-m5-dual-analyse`

- [x] Add `composer analyse` → `stan` then `psalm` (fail fast)
- [x] Optional: chip 10–20% highest-value baseline entries (types or SECURITY) with code fixes
- [x] Update `agent/cursor/psalm-7/README.md` appendix with commands and expected runtime
- [x] Confirm `phpcs` still independent (no ruleset in repo is OK)

**Gates:** `composer analyse`; full test + mutation gates.

**Exit:** One command runs both analyzers; PHPStan remains required.

---

## M6 — CI and SARIF

**Branch:** `stack/psalm-7-m6-ci`

- [x] Run `vendor/bin/psalm --init-ci`; commit `.github/workflows/psalm.yml` **or** `agent/cursor/psalm-7/ci-example.yml` if team defers workflow
- [x] Document SARIF upload and `ghcr.io/psalm/psalm` image pin
- [x] Document parallel job layout: `test` / `mutate` / `stan` / `psalm`
- [x] Optional: `composer audit` in same workflow (security review P2)
- [x] Note: Code Scanning requires GitHub Advanced Security or public repo policy

**Gates:** If workflow committed: workflow YAML valid; local `composer psalm:ci` dry-run documented. If doc-only: reviewer sign-off in milestone appendix.

**Exit:** CI path is unambiguous for the next human merge.

---

## Appendix template (fill per milestone)

### M1 — Dependencies
- Branch: `stack/psalm-7-m1-deps`
- Commit: `23fe15c`
- Line coverage: 86.54% (PHPUnit text report)
- Infection covered MSI: 91%
- Psalm: N/A (no config)
- Notes: `psalm/plugin-phpunit` locked at `0.22.0` (Psalm 7–compatible; no stable `1.0` yet). Gates: `test` pass (OAuth `file:///tmp/` keys in local `.env` for suite), `mutate` pass, `cs` N/A (no phpcs ruleset), `stan` 15 findings @ 1G (pre-existing, unchanged).

### M2 — Configuration
- Branch: `stack/psalm-7-m2-config`
- Commit: `44b6a68`
- Line coverage: 86.54% (unchanged)
- Infection covered MSI: 91% (unchanged from M1)
- Psalm: ERROR 1286, INFO 579, baseline 0 lines
- Notes: `vendor/bin/psalm --show-info=false` exit 2 as expected. Scope table in agent README.

### M3 — Baseline
- Branch: `stack/psalm-7-m3-baseline`
- Commit: `905954c`
- Line coverage: 86.54%
- Infection covered MSI: 91%
- Psalm: baselined ~1286 issues, 2598 XML lines, 195 files; single `psalm-baseline.xml`
- Notes: `findUnusedBaselineEntry="true"`. `composer psalm` exit 0.

### M4 — Taint stubs
- Branch: `stack/psalm-7-m4-taint-stubs`
- Commit: `b423420`
- Line coverage: 86.54%
- Infection covered MSI: 91% (mutate not re-run)
- Psalm: baseline 1284 codes (−2 vs M3); 2592 XML lines
- Notes: Built-in PSR-7 stubs sufficient (no `stubs/`). `RedirectValidator` taint escapes; `ScriptAlertResponse` sanitizes redirect URL. Aligns with security review open-redirect discussion (manual AGREE items unchanged).

### M5 — Dual analyse
- Branch: `stack/psalm-7-m5-dual-analyse`
- Commit: `613d5be`
- Line coverage: 86.54%
- Infection covered MSI: 91%
- Psalm: baseline 2592 lines
- Notes: `composer analyse` = `@stan` + `@psalm`. Stan uses `--memory-limit=1G`.

### M6 — CI example
- Branch: `stack/psalm-7-m6-ci`
- Commit: `cba9643`
- Line coverage: 86.54%
- Infection covered MSI: 91% (mutate not re-run this session)
- Psalm: baseline 2592 lines; `composer psalm:ci` exit 0
- Notes: `agent/cursor/psalm-7/ci-example.yml` (parallel `test` / `stan` / `psalm` + SARIF). No `.github/workflows`. `phpstan.neon` + bootstrap on this commit; unsafe-redirect unit test.
