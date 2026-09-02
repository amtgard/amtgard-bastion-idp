# Work milestone checklist — ork-iam 2.x rename (IDP server)

Ordered milestones for implementers. Check boxes in the **implementation** PR as work lands.

**Design pack:** [README](./README.md) · [architecture](./architecture.md) · [detailed design](./detailed-design.md)  
**Upstream:** [ork-iam MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

This pack is documents only. Do not implement inside the design commit.

---

## M0 — Preconditions

- [x] Confirm Packagist (or private Composer) serves `amtgard/ork-iam` **≥ 2.1.0** and `amtgard/ork-iam-orn-definitions` **≥ 2.0.0**
- [x] Confirm sibling facts still hold (or update design notes):
  - [x] `ork-iam` main = 2.x (through v2.1.1+); `1.x` branch has v1.4.1
  - [x] This IDP still pins `ork-iam` **v1.4.1** + orn-definitions `^0.9` until M2
  - [x] php-client 2.x remains wire-compatible; **do not** couple this PR to a client release
- [x] Confirm `ServiceCatalog::cases()` values still match 1.4.1 `OrkServices` (required for `BuiltInOrkPolicyServices` JWT inclusion)

**Exit:** agreed base SHA + confirmed dependency availability.

**M0 notes (2026-09-02):** Packagist lists `ork-iam` through **v2.1.1** and `ork-iam-orn-definitions` **v2.0.0**. Sibling `ork-iam` `main` is at tag v2.1.1; `1.x` still has v1.4.1. IDP `composer.lock` is still `v1.4.1`. php-client already requires `ork-iam ^2.1` / orn-definitions `^2.0`. `ServiceCatalog::cases()` string values and order match 1.4.1 `OrkServices` (`ORK` … `Application`). Base SHA: `3e728fa` (main / PR 65).

---

## M1 — Branch cut

- [x] Create branch `feature/ork-iam-2.x-ontology` (or equivalent) from current `main` (or agreed base)
- [x] Open draft PR early with link to `agent/cursor/2.x/` and empty checklist copy of [detailed-design §9](./detailed-design.md#9-acceptance-criteria-implementation-complete-when)
- [x] Do **not** change sibling repos (`ork-iam`, `ork-iam-orn-definitions`, `amtgard-idp-php-client`)

**Exit:** draft PR URL exists; working tree ready for goldens + dep bump.

**M1 notes:** Draft PR https://github.com/amtgard/amtgard-bastion-idp/pull/66 from `feature/ork-iam-2.x-ontology` (stacked on `docs/ork-iam-2.x-pack` / `3e728fa`). Sibling repos untouched.

**This is the first implementation milestone.**

---

## M2 — Capture goldens, then composer bump

Capture **before** the lockfile moves (or use existing test literals as the goldens — they already pin the bytes):

- [x] Record / confirm fixtures (do not “improve” them):
  - [x] ORNs: `Idp:0::::IDP/EditClient`, `Idp:0::::IDP/EditIdentity`, `ORK:0:::::ORK/AddKingdom`, `Skbc:0::::Officer/Approve`
  - [x] `IamServiceFormatParser::encode` → `["Configuration","tenant-id","Kingdom"]`
  - [x] Default `service_format` JSON list `["Configuration","Game","Kingdom","Park"]`
  - [x] JWT `policy` compare path in `tests/Utility/JwtTest.php` (same `Idp:…` policy strings)
- [x] Update `composer.json`:
  - [x] `"amtgard/ork-iam": "^2.1"` (or exact `2.1.1` if release train requires)
  - [x] `"amtgard/ork-iam-orn-definitions": "^2.0"`
- [x] Run `composer update amtgard/ork-iam amtgard/ork-iam-orn-definitions` (or full update if lock requires)
- [x] Confirm lockfile pins resolve to 2.x (not 1.4.1)
- [x] Expect compile failures — that is the signal for M3

**M2 notes:** Existing test literals are the goldens (unchanged). Lock resolved `amtgard/ork-iam` **v2.1.1** and `amtgard/ork-iam-orn-definitions` **v2.0.0**.

**Exit:** lockfile on 2.x; goldens saved; CI may be red until adapters land.

---

## M3 — IDP ORN subclasses + autoload register

- [x] `IdpFormat` / `ClientApplicationFormat`: `ornSegmentSchema()` + `ServiceCatalog`
- [x] `IdpClaim` / `IdpRequirement`: `public function ornSegmentSchema()`
- [x] `ClientApplicationClaim`: `ornSegmentSchema()` + `getPrefix()->name`
- [x] `src/register_orn_definitions.php`: `ServiceCatalog::Idp`
- [x] Do not change `getValidResourceMap` / `validResource` bodies

**Exit:** IDP-owned classes compile against 2.x parents.

---

## M4 — Registries, parsers, validators

- [x] `OrnClaimRegistry`, `BuiltInOrkPolicyServices`, `ClientApplicationFormatRegistry`
- [x] `IamServiceFormatParser`: `toCatalogEntry()`, `ORN\OrnSegmentLabel`, catalog typehints
- [x] `IamServiceValidator`: `validateCustomPrefix()`
- [x] Confirm `encode()` / default format **strings** unchanged
- [ ] Re-run `tests/Utility/IamServiceFormatParserTest.php`, `BuiltInOrkPolicyServicesTest.php`, `ClientApplicationFormatRegistryTest.php`, `OrnDefinitionsTest.php`

**Exit:** parse/register path compiles; format JSON goldens intact.

---

## M5 — Policy, JWT, HTTP controllers (identifiers only)

- [x] `UserAuthority`: `ServiceCatalog::Idp`; ORN string unchanged
- [x] `UserPolicyClaimRepository`: catalog compares; `provisos` column / concatenate / list JSON keys unchanged
- [x] `ApiController`: constructor enum only
- [x] `ClientResourcesController`: payload mapper typehint only; OpenAPI `provisos` / `service_format` **untouched**
- [x] `AuthorizationJwtAssembler`: PHPDoc only
- [x] `Jwt.php` / `UserPolicy.php`: verify no 1.x names (likely no edit)

**Exit:** mint / eval / Client IAM HTTP compile; no wire-key diffs in review.

---

## M6 — Tests rename + no-op proof

- [ ] Mirror symbol renames in tests listed in [detailed-design §4.1](./detailed-design.md#41-must-change-compile--type-break-on-21)
- [ ] Assert schema **method** names (`ornSegmentSchema`), not `serviceFormat`
- [ ] **Fail this milestone if bytes differ** from M2 goldens:
  - [ ] `UserPolicyClaimRepositoryTest` `toJson()` contains the same ORN strings (and not extra/missing)
  - [ ] `IamServiceFormatParserTest` encode/parse JSON
  - [ ] `ClientResourcesControllerTest` `service_format` arrays
  - [ ] `JwtTest` / `ApiControllerTest` policy / requirement ORN literals
- [ ] Do not rewrite tests to accept new wire

**Exit:** focused IAM / Client IAM / JWT tests green and byte-identical.

---

## M7 — Isolation & static analysis

- [ ] Grep `src/` + `tests/`: no `OrkServices`, `toOrkServices`, `getServiceIdentifier`, `getProviso`, `validateCustomServiceName`, or claim `serviceFormat()` overrides
- [ ] Grep: JSON / SQL still use `provisos`, `service_format`, `iam_service`
- [ ] `composer stan` green
- [ ] `composer cs` green if required by CI

**Exit:** stan + isolation checks pass.

---

## M8 — Full PHPUnit

- [ ] `composer test` green
- [ ] No unexplained coverage drop if a gate exists
- [ ] Spot-check: admin ORN, default format, Client IAM list payload shape `{ service, provisos, resource }`

**Exit:** unit CI green.

---

## M9 — Optional operator docs

- [ ] `templates/api.md`: only if it names the **PHP** type `OrkServices`; keep every JSON example
- [ ] Do not add VERSION / changelog product fiction
- [ ] Do not edit `.cursor/plans`

**Exit:** docs still describe the same HTTP API.

---

## M10 — PR ready; human merge / tag / deploy (optional, last)

- [ ] Implementation PR description includes:
  - [ ] Link to this design pack
  - [ ] Link to upstream MIGRATION-2.0
  - [ ] Checklist copy of [detailed-design §9](./detailed-design.md#9-acceptance-criteria-implementation-complete-when)
  - [ ] Statement: **no wire change**; php-client 0.12 / 1.4.1 / 2.x unaware
- [ ] No unrelated refactors
- [ ] Reviewers: owner + anyone maintaining Client IAM / JWT consumers
- [ ] **Human (optional):** merge when CI green
- [ ] **Human (optional):** tag / deploy when the release train says so

**Exit:** PR ready. Merge, tag, and deploy are **not** required for the agent to close the implementation branch.

---

## Suggested calendar order (summary)

```
M0 preconditions → M1 branch → M2 goldens + composer bump → M3 ORN subclasses
  → M4 registries/parsers → M5 policy/JWT/HTTP → M6 tests + no-op proof
  → M7 stan/isolation → M8 full PHPUnit → M9 optional docs → M10 PR / human
```

Typical effort: **1 focused day** if Packagist tags exist; longer if path-repo Composer or unexpected 2.x API mismatch (then **stop** — see detailed-design §8).

---

## Out of order / do not

- [ ] ~~Implement the rename inside the design-docs change~~
- [ ] ~~Modify `../ork-iam`, `../ork-iam-orn-definitions`, or `../amtgard-idp-php-client`~~
- [ ] ~~Change IDP wire field names or ORN strings~~
- [ ] ~~Adopt ClaimBuilder / PolicyDocument / new endpoints~~
- [ ] ~~Shim vendor exception messages to 1.x wording~~
- [ ] ~~VERSION / build-id / OAuth / Apple login work~~
- [ ] ~~Force-push shared branches~~
