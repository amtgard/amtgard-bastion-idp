# Psalm 7 adoption — risks

## False positives and blind spots

| Risk | Impact | Mitigation |
|------|--------|------------|
| **Twig auto-escape** | Psalm flags tainted data passed to `render()` even when templates escape | Prefer fixing tainted context at source; use baseline for known-safe patterns; do not disable taint globally |
| **JSON APIs** | `TaintedHtml` on values that only become JSON | Suppress per-endpoint or narrow sink kinds; ensure `JSON_THROW_ON_ERROR` paths stay typed |
| **`ScriptAlertResponse`** | Mixed HTML/JS sink; hard to prove message is safe | Route user-facing errors through fixed strings; keep provider errors baselined until reviewed |
| **Open redirect** | Psalm 7 `TaintedUrlPath` / URL kinds vs `RedirectValidator` | M4 annotations on sanitizer; **manual review** still required for `Location` headers that skip validator (see `BaseAuthController`) |
| **Pure / immutable inference** | Without `@psalm-pure`, taint spreads across call sites | Optional later milestone; not required for first green baseline |
| **Session / `$_SESSION`** | Limited modeling | OWASP items (unserialize, CSRF) in [security-review-owasp-cisa.md](../security-review-owasp-cisa.md) — taint won’t catch |
| **IDOR / authZ** | No replacement for middleware tests and design review | Tag Psalm hits; don’t conflate with CISA checklist completion |
| **Third-party OAuth / League** | Stubs may miss library internals | Keep `vendor/` out of scope; flag only call sites in `src/` |

## Vendor packages and stubs

- Psalm 7 **library stubs** cover PSR-7, Monolog, Guzzle, etc. **Amtgard** packages (`amtgard/active-record-orm`, `ork-iam`, …) are not stubbed; wrong types in vendor may surface as app issues or be ignored depending on `projectFiles`.
- **Upgrades:** Psalm minor releases may break plugin API (`psalm/psalm-plugin-api`); pin lockfile and re-baseline after bumps.
- **`ext-redis`:** CI images must install extensions if bootstrap loads container touching Redis-backed services during analysis (prefer lean entry or `--ignore-bootstrap` if we add one).

## Dual analyzer cost

| Cost type | Detail |
|-----------|--------|
| **Developer time** | Two tools, two baselines (PHPStan implicit “no config” vs Psalm explicit baseline) |
| **CI minutes** | Parallel `stan` + `psalm` jobs add ~2–5 min each on ~226 files; taint is faster in v7 but not free |
| **Cognitive load** | Same line may fail differently; use `composer analyse` after M5 |
| **Drift** | Changing phpdoc for PHPStan may shift Psalm; run both before merge |
| **Baseline rot** | Large M3 baseline hides real regressions unless `findUnusedBaselineEntry` and periodic shrink (M5+) |

## Migration / dependency risks

- Staying on **PHPStan 1.4** while Psalm 7 is current is intentional; PHPStan upgrade is a **separate stack** if desired.
- **Removing PHPStan** would save dual cost but loses tests/ scope and team familiarity — **not recommended** in this plan.

## Blocking or high-impact decisions (for user)

1. **M6:** Commit real `.github/workflows/psalm.yml` vs doc-only `ci-example.yml`.
2. **M3:** Single baseline vs split type/taint baselines (default single).
3. **Scope:** When to add `tests/` to Psalm (mirror PHPStan) — defer until M6 or later.
4. **Policy:** Whether SECURITY findings in Code Scanning block merge on private repo (GHAS availability).
5. **Baseline budget:** Target maximum baseline lines before mandatory shrink (suggest review at >500 SECURITY-suppressed paths).
