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
