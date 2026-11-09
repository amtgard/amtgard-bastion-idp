# Psalm 7 — security taint + type checking (IDP)

Adopt [Psalm 7](https://psalm.dev/articles/psalm-7) on the Amtgard IDP Slim app with **combined type and taint analysis**, scoped initially to `src/` (~226 PHP files), while **keeping PHPStan** (`composer stan` on `src` + `tests`) as the existing type gate. Psalm complements PHPStan by flagging **SECURITY** issues (XSS, open redirect, SQL, SSRF, and related flows) and by shipping **PSR-7 library taint stubs** in v7; it does **not** replace operational controls, IDOR review, or the manual findings in [security-review-owasp-cisa.md](../security-review-owasp-cisa.md) (session hardening, CSRF deployment, `unserialize`, JWT-in-URL, rate limits, and similar design fixes remain out of scope for static taint alone).

**Non-goals:** removing PHPStan; analysing `vendor/` or `templates/` in the first pass; wiring GitHub Actions in the same stack unless a milestone explicitly opts in; treating a green baseline as “ship-ready” security; blocking releases on baseline shrink before the team agrees on CI policy.

**Deliverables:** [implementation-plan.md](./implementation-plan.md) · [milestones.md](./milestones.md) · [risks.md](./risks.md)

**Resume:** stack tip after planning → `stack/psalm-7` (this pack only). First implementation branch → `stack/psalm-7-m1-deps`.

## Analyzer scope (M2+)

| Tool | Paths |
|------|--------|
| `composer stan` | `src/`, `tests/` |
| `composer psalm` | `src/` only (`tests/` deferred; PHPUnit plugin registered for a later scope widen) |

Taint analysis is on by default in Psalm 7 (`runTaintAnalysis` in `psalm.xml`). SARIF output from `composer psalm:security` lands under `build/` (gitignored).

**Developer gates (post-M3):** `composer test`, `composer mutate`, `composer stan`, **`composer psalm`** (green against committed `psalm-baseline.xml`). Post-M5: **`composer analyse`** runs `@stan` then `@psalm` (fail fast).

## Commands appendix (local)

| Script | Typical runtime (M5, laptop) | Notes |
|--------|-------------------------------|--------|
| `composer stan` | ~20–25s @ 1G memory | `src/` + `tests/` |
| `composer psalm` | ~7–15s | `src/` only, `--no-cache` |
| `composer analyse` | ~30–40s | stan then psalm |
| `composer psalm:security` | ~7–15s | writes `build/psalm-security.sarif` |
| `composer cs` | N/A | no PHPCS ruleset in repo |

PHPStan may need `php -d memory_limit=1G vendor/bin/phpstan analyse src tests` on memory-constrained hosts; default `composer stan` matches existing project script.

**Local gates (after M3):** `composer test`, `composer mutate`, `composer stan`, **`composer psalm`** (green via committed `psalm-baseline.xml`; single baseline for types + taint).

## App taint model (M4)

| Escape / guard | Symbol |
|----------------|--------|
| Open-redirect sanitizer | `RedirectValidator::sanitize`, `sanitizeOrNull` (`@psalm-taint-escape url`) |
| Same-host / relative guard | `RedirectValidator::isSafe` |
| JS redirect HTML | `ScriptAlertResponse::alertAndRedirect` (redirect URL through `RedirectValidator`; message via `urlencode` + `json_encode`) |
| OAuth session redirect | `BaseAuthController` reads `$_SESSION['redirect']` through `sanitizeOrNull` before `Location` |
| PSR-7 inputs | Psalm 7 `loadLibraryStubs` covers Slim/PSR-7 request accessors — no project `stubs/` yet |
| Twig HTML sinks | `Twig\Environment::render` call sites in `src/`; templates auto-escape by default (templates not in Psalm scope) |

## Commands and runtime (M5)

| Command | Role | Approx. runtime (dev laptop) |
|---------|------|------------------------------|
| `composer analyse` | PHPStan (`src`+`tests`) then Psalm (`src`); fails fast | ~90s (`stan` ~75s, `psalm` ~8s) |
| `composer psalm:ci` | GitHub Actions annotation format | ~8s |
| `composer psalm:security` | SARIF → `build/psalm-security.sarif` | ~8s |
| `composer cs` | PHPCS (no ruleset in repo; exits 3) | &lt;1s |

`composer cs` remains independent of `composer analyse`.
