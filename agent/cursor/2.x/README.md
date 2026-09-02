# ork-iam 2.x / ontology rename — IDP server design pack

Design documents for migrating **this** Amtgard IDP (`amtgard/idp-server`) from `amtgard/ork-iam` **v1.4.1** to **^2.1** and `amtgard/ork-iam-orn-definitions` **^0.9** to **^2.0**.

This is an **operational synonym / type-name update** on the server. Authorization semantics, JWT minting, policy merge/eval, and Client IAM HTTP behavior stay identical. ORN **strings**, JWT claim payloads, and HTTP JSON keys do not change.

**Scope of this pack:** documents only. No application code, `composer.lock`, or tests land until a follow-up implementation branch.

Sibling **php-client** already has a 2.x pack at `../amtgard-idp-php-client/agent/cursor/2.x/` and is wire-compatible with an IDP still on 1.4.1. That client bump is **independent**. Do not copy that pack wholesale — this pack is the **server** (in-process IAM consumer that mints JWTs and owns Client IAM storage).

## Documents

| Doc | Purpose |
|-----|---------|
| [architecture.md](./architecture.md) | IDP as in-process IAM consumer vs php-client; wire vs local PHP; why this is naming, not product |
| [detailed-design.md](./detailed-design.md) | File-level touch map of every `Amtgard\IAM\*` import and IDP-owned subclass; must-change PHP vs must-not-change wire; blockers |
| [milestones.md](./milestones.md) | Ordered, checkable milestones from branch cut through tests / no-op proof / PR. Human merge/tag/deploy is last and optional |

## Upstream references (read-only)

- [`ork-iam/docs/MIGRATION-2.0.md`](../../../../ork-iam/docs/MIGRATION-2.0.md) — canonical 1.x → 2.x rename table (do not duplicate wholesale; map only this server’s symbols in [detailed-design.md](./detailed-design.md))
- [`ork-iam/docs/ORN-ONTOLOGY.md`](../../../../ork-iam/docs/ORN-ONTOLOGY.md) — glossary (prefix, schema, label, segment, catalog)
- [`ork-iam/CHANGELOG.md`](../../../../ork-iam/CHANGELOG.md) — v2.0.0 / v2.1.0 / v2.1.1
- Sibling repos: `../ork-iam` (`main` = 2.x through **v2.1.1**; `1.x` maintains **v1.4.1**), `../ork-iam-orn-definitions` (**v2.0.0** requires `ork-iam ^2.1`), `../amtgard-idp-php-client` (already on 2.x; wire-compatible with IDP 1.4.1)

## Target dependency pins

Current (`composer.json`):

```json
"amtgard/ork-iam": "v1.4.1",
"amtgard/ork-iam-orn-definitions": "^0.9"
```

Target:

```json
"amtgard/ork-iam": "^2.1",
"amtgard/ork-iam-orn-definitions": "^2.0"
```

Exact `2.1.1` is acceptable if the release train prefers a hard pin; prefer `^2.1` for patch flexibility.

## Hard constraints (product owner)

1. **No algorithmic changes.** Synonym / type-name update only.
2. **Wire-agnostic.** Do not change ORN strings, JWT claim payloads, or HTTP JSON keys (`provisos`, `service_format`, `iam_service`, policy line arrays, …). Integrators on php-client 0.12 / 1.4.1 / 2.x must keep working without noticing.
3. **Not a capability project.** `ClaimBuilder` / `PolicyDocument` already exist on 1.4.1. Do not “improve” evaluation, add endpoints, or refactor unrelated IDP code (versioning, OAuth, Apple login, …).
4. **Documents only** in this pack. Implementation is a follow-up branch.

## Out of scope

- php-client release (already independent)
- SKBC / integrator apps
- Retiring ork-iam 1.x
- VERSION / build-id work
- IAM 2.x “new primitives” (`ClaimBuilder` adoption, `PolicyDocument` JWT envelope, new endpoints)
