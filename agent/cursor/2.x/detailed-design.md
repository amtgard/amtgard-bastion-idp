# Detailed design — ork-iam 2.x ontology rename (IDP server)

**Companion:** [architecture.md](./architecture.md) · [milestones.md](./milestones.md) · upstream [MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

This document maps **this server’s** symbols and files. It does not reproduce the full upstream rename table.

---

## 1. Decisions (locked for implementers)

| ID | Decision | Rationale |
|----|----------|-----------|
| D1 | Target deps: `"amtgard/ork-iam": "^2.1"` (or exact `2.1.1`) and `"amtgard/ork-iam-orn-definitions": "^2.0"` | Matches orn-definitions v2.0.0 (`requires ork-iam ^2.1`) |
| D2 | Synonym / type-name only. No evaluation, minting, merge, or HTTP behavior changes | Product-owner constraint |
| D3 | Wire keys and ORN strings stay (`provisos`, `service_format`, `iam_service`, policy line arrays) | php-client 0.12 / 1.4.1 / 2.x must not notice |
| D4 | IDP subclasses override `ornSegmentSchema()` (not `serviceFormat()`) after the bump | 2.x **removes** `serviceFormat()`; parent is now `ornSegmentSchema()` |
| D5 | Do not adopt `ClaimBuilder` / `PolicyDocument` / new endpoints | Not a capability project; 1.4.1 already had those APIs |
| D6 | Do not rename IDP helper **class** names (`BuiltInOrkPolicyServices`, `IamServiceFormatParser`, `serviceFormatPayload`) | Compile does not require it; wire vocabulary can stay in IDP-owned identifiers |
| D7 | Docs-only for this pack; implementation is a follow-up branch | Avoid mixing design review with code churn |
| D8 | Fail the no-op milestone if JWT / ORN / `service_format` bytes differ from pre-rename goldens | Proof that this is naming, not product |

---

## 2. Package symbol → 2.x mapping

Upstream owns the full table. This is what **appears in this repo**.

| Location / symbol (1.4.1) | 2.x equivalent |
|---------------------------|----------------|
| `Amtgard\IAM\OrkServices` | `Amtgard\IAM\Catalog\ServiceCatalog` |
| `OrkServices::Idp` / `::Configuration` / `::ORK` / … | `ServiceCatalog::Idp` / `::Configuration` / `::ORK` / … (same `->value` strings) |
| `OrnSegmentLabel::toOrkServices()` | `OrnSegmentLabel::toCatalogEntry()` |
| `Claim::serviceFormat()` / `Requirement::serviceFormat()` | `ornSegmentSchema()` |
| `ORNFormat::serviceFormat()` | `ORNFormat::ornSegmentSchema()` |
| `$claim->getServiceIdentifier()` | `$claim->getPrefix()` |
| `$claim->getProviso($label)` | `$claim->getSegment($label)` |
| `$proviso->getSegmentValue()` | `$segment->getValue()` |
| `OrnClassMap::validateCustomServiceName()` | `OrnClassMap::validateCustomPrefix()` |
| `use Amtgard\IAM\Orn\OrnSegmentLabel` | `use Amtgard\IAM\ORN\OrnSegmentLabel` (2.x namespace is `ORN`) |
| `ClaimFactory` / `PolicyFactory` / `RequirementFactory` / `Policy` / `Claim` / `Resource` / `ORNFormat` | **Same FQCNs** |
| `ClaimBuilder` / `PolicyBuilder` / `PolicyDocument` | **Do not start using** |

### 1.4.1 aliases (note only)

On 1.4.1, `OrkResourceName` already has `ornSegmentSchema()` → `serviceFormat()`, `getPrefix()` → `getServiceIdentifier()`, `getSegment()` → `getProviso()`. The IDP source still calls the **old** names. After `^2.1`, those old names are gone — IDP must use the 2.x names.

### Visibility (still a synonym)

1.x IDP overrides are `protected function serviceFormat()`. 2.x `OrkResourceName::ornSegmentSchema()` is **`public` abstract** (ork-iam fixtures and orn-definitions 2.0 also use `public`). Implementers must match the parent (`public function ornSegmentSchema()`). That is a signature alignment, not an algorithm change.

`ORNFormat::ornSegmentSchema()` stays `public static` (same as today’s `serviceFormat()` on `IdpFormat` / `ClientApplicationFormat`).

### Constructor

`OrkResourceName::__construct` is `OrnPrefix|ServiceCatalog|string` (was `ServiceIdentifier|OrkServices|string`). `new IdpRequirement(ServiceCatalog::Idp, $orn)` is the synonym of `new IdpRequirement(OrkServices::Idp, $orn)`.

---

## 3. Must-not-change wire

| Surface | Keep exactly |
|---------|----------------|
| ORN examples | `Idp:0::::IDP/EditClient`, `Idp:0::::IDP/EditIdentity`, `ORK:0:::::ORK/AddKingdom`, `Skbc:0::::Officer/Approve`, `Idp:0:0:0:0:IDP/EditClient` (API test) |
| JWT `policy` | `Policy::toJson()` — sorted JSON array of those strings |
| Client IAM JSON keys | `provisos`, `resource`, `idp_user_id`, `service`, `iam_service`, `service_format`, `is_default`, `claims` |
| OpenAPI `OA\Property` names | same keys on `ClientResourcesController` |
| Admin form / DB | `iam_service`, `iam_service_format`; table columns `service`, `provisos`, `resource` |
| Default format encode | `["Configuration","Game","Kingdom","Park"]` |
| `/api/is-authorized` | body keys `policy`, `requirement`; response `{ "is_authorized": bool }` |
| `UserAuthority` requirement string | `"Idp:0::::IDP/EditClient"` |

`ClientResourcesController::serviceFormatPayload()` **method name** and JSON key `service_format` stay. Only the `OrkServices` typehint inside the mapper changes.

---

## 4. File-level touch map

### 4.1 Must change (compile / type break on ^2.1)

#### IDP-owned ORN subclasses

| File | Change |
|------|--------|
| `src/Models/Orn/IdpFormat.php` | `OrkServices` → `ServiceCatalog`; `serviceFormat()` → `ornSegmentSchema()`; same four catalog cases |
| `src/Models/Orn/IdpClaim.php` | `public function ornSegmentSchema(): array` returning `IdpFormat::ornSegmentSchema()` |
| `src/Models/Orn/IdpRequirement.php` | Same as claim |
| `src/Models/Orn/ClientApplicationFormat.php` | Same as `IdpFormat` (default four slots; wildcard resource map unchanged) |
| `src/Models/Orn/ClientApplicationClaim.php` | Override `ornSegmentSchema()`; `getServiceIdentifier()->name` → `getPrefix()->name`; `validResource()` stays `return true` |

#### Registration / catalogs

| File | Change |
|------|--------|
| `src/register_orn_definitions.php` | `OrnClassMap::registerClaim/Requirement(ServiceCatalog::Idp, …)` |
| `src/Utility/OrnClaimRegistry.php` | `OrkServices::Idp->value` / `OrkServices::tryFrom` → `ServiceCatalog` |
| `src/Utility/BuiltInOrkPolicyServices.php` | `OrkServices` → `ServiceCatalog` (`cases()`, `tryFrom`, `->value`). **Do not change the returned string list.** |
| `src/Utility/ClientApplicationFormatRegistry.php` | PHPDoc `list<OrkServices\|string>` → `list<ServiceCatalog\|string>`; param name `$provisoSlots` may stay (IDP-owned) |

#### Parsers / validators

| File | Change |
|------|--------|
| `src/Utility/IamServiceFormatParser.php` | Import `ServiceCatalog` + `Amtgard\IAM\ORN\OrnSegmentLabel`; `toOrkServices()` → `toCatalogEntry()`; default format + `encode()` typehints |
| `src/Utility/IamServiceValidator.php` | `OrnClassMap::validateCustomPrefix($iamService)` |

#### JWT / policy / HTTP (PHP identifiers only)

| File | Change |
|------|--------|
| `src/Utility/UserAuthority.php` | `new IdpRequirement(ServiceCatalog::Idp, "Idp:0::::IDP/EditClient")` |
| `src/Controllers/Api/ApiController.php` | Same constructor rename; `PolicyFactory::fromOrn` / `isAuthorized` stay |
| `src/Persistence/Common/Repositories/UserPolicyClaimRepository.php` | `OrkServices::Idp` / `::ORK` compares and PHPDoc `{@see}` → `ServiceCatalog`; **concatenation `$service.$provisos.$resource` and JSON row keys stay** |
| `src/Controllers/Resource/ClientResourcesController.php` | `serviceFormatPayload` mapper: `ServiceCatalog\|string`; OpenAPI / `$body['provisos']` / `$body['service_format']` **untouched** |
| `src/Models/AuthorizationJwtAssembler.php` | PHPDoc `{@see OrkServices}` → `ServiceCatalog` only |

#### Tests (mirror PHP; keep wire fixtures)

| File | Change |
|------|--------|
| `tests/Utility/OrnDefinitionsTest.php` | Catalog enum; `getServiceIdentifier()` → `getPrefix()`; `getProviso()` / `getSegmentValue()` → `getSegment()` / `getValue()`; ORN strings stay |
| `tests/Utility/IamServiceFormatParserTest.php` | Catalog types; encoded JSON `'["Configuration","tenant-id","Kingdom"]'` stays |
| `tests/Utility/BuiltInOrkPolicyServicesTest.php` | Compare against `ServiceCatalog::cases()` values (same strings) |
| `tests/Utility/ClientApplicationFormatRegistryTest.php` | Catalog cases in stored lists |
| `tests/Models/ClientApplicationClaimTest.php` | `getSegment` / `getValue`; ORN `Skbc:9:8:Custom/Action` stays |
| `tests/Models/ClientApplicationFormatTest.php` | Call `ornSegmentSchema()` (count 4 unchanged) |
| `tests/Persistence/UserPolicyClaimRepositoryTest.php` | Catalog enum; assertContains goldens `ORK:0:::::ORK/AddKingdom`, `Idp:0::::IDP/EditClient`, `Idp:0::::IDP/EditIdentity`, `Skbc:0::::Officer/Approve` **must still pass** |
| `tests/Models/ModelsTest.php` | No type rename required (`ClaimFactory::createOrn("Idp:0::::IDP/EditClient")`) — verify after bump |

`composer.json` pins as in [README](./README.md). Refresh lock **only on the implementation branch**.

### 4.2 Likely unchanged (verify after composer update)

| File | Why |
|------|-----|
| `src/Utility/Jwt.php` | `PolicyFactory::fromOrn` / policy JSON compare only. Unused `ClaimFactory` import — leave unless stan/cs forces it |
| `src/Persistence/Common/Repositories/UserPolicy.php` | `Policy` FQCN + `toJson()` |
| `src/Utility/IamServiceFormatValidator.php` | Delegates to parser; no IAM types |
| `src/Utility/Client/ClientIamAdminInput.php` | Form keys `iam_service` / `iam_service_format` |
| `src/Controllers/Resource/ResourcesController.php` | Injects `UserAuthority`; no IAM types |
| OAuth / Apple / Connect / VERSION / Slim bootstrap | No `Amtgard\IAM` imports |

### 4.3 Consumer-facing docs (optional, wire-safe)

| Path | When to touch |
|------|----------------|
| `templates/api.md` | Mentions PHP `OrkServices` as slot-name vocabulary. Optional: say “built-in catalog labels” without renaming JSON examples |
| `templates/management/clients.twig` | Form field names stay |
| OpenAPI attributes on `ClientResourcesController` | **Do not** rename properties |

---

## 5. Adapter patterns (server)

### 5.1 Format override

```php
// Before
public static function serviceFormat(): array {
    return [OrkServices::Configuration, OrkServices::Game, OrkServices::Kingdom, OrkServices::Park];
}

// After
public static function ornSegmentSchema(): array {
    return [ServiceCatalog::Configuration, ServiceCatalog::Game, ServiceCatalog::Kingdom, ServiceCatalog::Park];
}
```

`IdpClaim` / `IdpRequirement` / `ClientApplicationClaim` must implement the 2.x abstract (`ornSegmentSchema`), not the removed 1.x `serviceFormat`.

### 5.2 Stored claim parse (unchanged algorithm)

```php
$orn = $service . $this->userClaims->provisos . $this->userClaims->resource;
return ClaimFactory::createOrn($orn);
```

### 5.3 Service-format encode (PHP types change; JSON bytes do not)

```php
array_map(
    static fn (ServiceCatalog|string $slot): string => $slot instanceof ServiceCatalog ? $slot->value : $slot,
    $slots
);
```

`ServiceCatalog` case values were checked against 1.4.1 `OrkServices` — **identical** (`ORK`, `Configuration`, … `Idp`, `Documents`, … `Application`).

### 5.4 Integrator registration

`OrnClaimRegistry::registerForService` still:

1. skip `ServiceCatalog::Idp->value`
2. skip already-registered prefixes
3. skip built-in `ServiceCatalog::tryFrom($service)`
4. else `OrnClassMap::registerClaim($service, ClientApplicationClaim::class)`

`OrnClassMap::registerClaim` accepts `string|ServiceCatalog` on 2.x (was `string|OrkServices`).

---

## 6. Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Accidental wire-key rename while editing OpenAPI / payload helpers | php-client + raw HTTP break | Grep: `provisos`, `service_format`, `iam_service` still present as **strings**; no-op golden tests |
| `Policy::toJson()` sort / `buildOrn()` drift | JWT `policy` bytes change | Compare to pre-bump fixtures; fail milestone if different |
| Catalog case added/removed in a future 2.x patch | `BuiltInOrkPolicyServices::serviceNames()` JWT inclusion set changes | Pin `^2.1` and confirm `ServiceCatalog::cases()` values vs 1.4.1 at bump time (identical on 2.1.1) |
| Vendor exception **wording** (not rules) | HTTP 400 `error` text if IDP forwards `$e->getMessage()` | See §8 watch item — do not invent a message shim |
| Linux CI + `Amtgard\IAM\Orn` vs `ORN` | Autoload miss if import left on 1.x case | Use `Amtgard\IAM\ORN\OrnSegmentLabel` |
| Scope creep (OAuth, Apple, VERSION) | Unrelated regressions | Touch only IAM import sites |
| Packagist lag for orn-definitions ^2.0 | Composer fail | Confirm tags in M0; path repos only for local |

---

## 7. Non-goals

- Implementing the migration in this design PR
- php-client release, SKBC, retiring ork-iam 1.x
- VERSION / build-id work
- IAM 2.x new primitives (`ClaimBuilder` in IDP, `PolicyDocument` JWT envelope, new routes)
- Renaming HTTP / OpenAPI / DB column `provisos` to `segments`
- Renaming IDP helper classes to ontology vocabulary
- “Improving” `Policy::isAuthorized`, claim caps, or Client IAM validation

---

## 8. Blockers that are **not** pure synonyms

None found that change authorization, JWT minting, or Client IAM HTTP **rules**.

Verified same on ork-iam 1.4.1 vs 2.1.1:

- `ServiceCatalog` cases === `OrkServices` cases (same string values, same order)
- `Policy::toJson()` still `buildOrn()` + `sort` + `json_encode`
- Custom prefix regex still `/^[A-Z][A-Za-z0-9]*$/`
- Claim vs Requirement ORN matchers unchanged
- `ClaimFactory::createOrn` / `PolicyFactory::fromOrn` FQCNs unchanged (`fromOrn` now delegates to `PolicyBuilder` — upstream documents behavioral parity)

**Watch item (not a synonym, not an IDP algorithm):** ork-iam 2.x rewords some `InvalidArgumentException` messages (`validateCustomPrefix`, empty-prefix, collision). `IamServiceValidator` and `UserPolicyClaimRepository::assertClaimParses` (`'Invalid ORN claim: ' . $e->getMessage()`) plus Client IAM `jsonError(..., $e->getMessage())` will **forward** that vendor text. Status codes and accept/reject sets stay the same. Existing IDP tests assert exception **class**, not vendor wording (`ClientValidatorsTest`). **Do not invent a compatibility message map.** If a later test asserts exact vendor text, update the test string only.

**Not a blocker:** `ornSegmentSchema()` visibility `protected` → `public` to match 2.x parent.

If implementation discovers a missing method, different default format, or `createOrn` rejection of an ORN that 1.4.1 accepted, **stop and flag** — do not invent a behavior fix.

---

## 9. Acceptance criteria (implementation complete when…)

- [x] `composer.json` requires `ork-iam ^2.1` (or `2.1.1`) and `ork-iam-orn-definitions ^2.0`; lock resolves on CI
- [x] No remaining `OrkServices`, `serviceFormat()` overrides, `toOrkServices()`, `getServiceIdentifier()`, `getProviso`, or `validateCustomServiceName` in `src/` / `tests/` (historical comments only if unavoidable)
- [x] HTTP JSON keys, OpenAPI property names, and stored column names unchanged
- [x] PHPUnit goldens for ORN strings / `Policy::toJson()` / `service_format` encode **byte-identical** to pre-rename capture
- [x] `UserAuthority` still evaluates `"Idp:0::::IDP/EditClient"`
- [x] `composer test` and `composer stan` green
- [x] No unrelated refactors (OAuth, Apple, VERSION, Client IAM feature work)

---

## 10. Suggested commit / PR framing (implementation phase)

- Branch: `feature/ork-iam-2.x-ontology`
- PR title: e.g. `Migrate IDP to ork-iam ^2.1 ontology (rename only)`
- Body: link this design pack + upstream MIGRATION-2.0; checklist from §9 and [milestones.md](./milestones.md)
