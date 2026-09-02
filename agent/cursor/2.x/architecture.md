# Architecture — ork-iam 2.x ontology rename (IDP server)

**Package:** `amtgard/idp-server` (this repo)  
**From:** `amtgard/ork-iam` `v1.4.1` + `ork-iam-orn-definitions` `^0.9`  
**To:** `amtgard/ork-iam` `^2.1` + `ork-iam-orn-definitions` `^2.0`  
**Companion upstream:** [ork-iam MIGRATION-2.0.md](https://github.com/amtgard/ork-iam/blob/main/docs/MIGRATION-2.0.md)

---

## 1. System context

The IDP is an **in-process IAM consumer**. It does not speak ork-iam over HTTP. It loads `Amtgard\IAM\*` in the same PHP process, registers IDP-owned claim/requirement classes, persists ORN **parts** as strings, and mints authorization JWTs whose `policy` claim is a JSON array of full ORN strings.

```
┌─────────────────────────────────────────────────────────────────┐
│  Amtgard IDP process                                            │
│    OAuth / login / resources  (unchanged product)               │
│    AuthorizationJwtAssembler ──► UserPolicy.toPolicyJson()      │
│         └── ClaimFactory / Policy / IdpClaim (ork-iam 2.x)      │
│    UserAuthority.isAdmin ──────► Policy::isAuthorized           │
│    ApiController.isAuthorized ─► PolicyFactory::fromOrn         │
│    ClientResourcesController ──► HTTP { provisos, resource,     │
│                                    service_format, iam_service }│
│         └── UserPolicyClaimRepository (string columns)          │
│    register_orn_definitions.php + orn-definitions register.php  │
└───────────────┬─────────────────────────────────────────────────┘
                │ HTTP wire (ORN strings + JSON keys)
                ▼
┌─────────────────────────────────────────────────────────────────┐
│  Integrators                                                    │
│    php-client 0.12 / 1.4.1 / 2.x   (any ork-iam major)          │
│    raw HTTP / other languages                                   │
└─────────────────────────────────────────────────────────────────┘
```

| Concern | Owner | ork-iam version coupling |
|---------|-------|--------------------------|
| OAuth, social login, sessions, Apple | This server, non-IAM | **None** |
| Client IAM HTTP (`/resources/client/*`) | This server → integrator HTTP | **Wire strings** only; PHP types are local |
| JWT `policy` / `aud` / `iss` / `client_metadata` | This server mints | **Wire JSON**; ORN lines built by `Policy::toJson()` |
| `Policy::isAuthorized` (admin gate, `/api/is-authorized`) | This process | This server’s ork-iam only |
| php-client local `checkAuthorization` | Client process | Client’s ork-iam (may already be 2.x) |

**Implication:** php-client 2.x can already talk to this IDP on 1.4.1. This IDP bump is **independent**. The only coupling that matters is **identical wire bytes**.

---

## 2. IDP vs php-client

| | php-client 2.x pack | This IDP pack |
|--|---------------------|---------------|
| Role | Out-of-process HTTP client + local eval | In-process minter / store / eval |
| Why 2.x | Consumer type names + isolation | Server type names so composer can leave 1.4.1 |
| Wire ownership | Adapts to IDP keys (`provisos`, `service_format`) | **Defines** those keys; must not rename them |
| Local helpers | Keeps `OrnWireFormat` / `OrnWireParts` | No client wire splitter; concatenates `service + provisos + resource` |
| Public façade | `IdpClient` method names stay | HTTP routes / OpenAPI field names stay |
| Can ship first? | Already did, against IDP 1.4.1 | Ships later; must not break those clients |

This is **operational (naming)**, not product: no new Client IAM capabilities, no `PolicyDocument` JWT envelope, no evaluation rewrite. 1.4.1 already has `ClaimBuilder` / `PolicyBuilder` / `PolicyDocument`. The IDP does not need to start using them.

---

## 3. Boundaries

### 3.1 Wire (must not change)

| Layer | Format | Who defines it |
|-------|--------|----------------|
| Full ORN string | `Prefix:seg0:seg1:…:Resource[/Procedure]` | ork-iam `buildOrn()` / `ClaimFactory::createOrn()` |
| JWT `policy` claim | JSON array of those ORN strings (`Policy::toJson()`) | IDP mint path |
| Client IAM claim body | `{ "provisos": ":0::::", "resource": "Officer/Approve" }` | IDP OpenAPI — **not** renamed |
| Stored claim row | columns `service`, `provisos`, `resource` | DB + list JSON `{ service, provisos, resource }` |
| Service format body | `{ "iam_service": "Skbc", "service_format": ["Configuration","Game",…], "is_default": true }` | IDP JSON |
| Admin form fields | `iam_service`, `iam_service_format` | Management UI / `ClientIamAdminInput` |
| `/api/is-authorized` body | `{ "policy": "[…]", "requirement": "Idp:…" }` → `{ "is_authorized": bool }` | Existing API |

HTTP / OpenAPI / docs field names that say `proviso` or `service_format` **stay**. Only PHP identifiers change.

### 3.2 Local PHP (must change)

Every `use Amtgard\IAM\OrkServices`, every `serviceFormat()` override, every `getProviso` / `getServiceIdentifier` / `toOrkServices` / `validateCustomServiceName` call site in `src/` and `tests/`. See [detailed-design.md](./detailed-design.md).

IDP-owned subclasses (`IdpClaim`, `IdpRequirement`, `IdpFormat`, `ClientApplicationClaim`, `ClientApplicationFormat`) currently override `serviceFormat()`. On 2.x the parent abstract is `ornSegmentSchema()` and the 1.x name is **removed**. That override rename is still a synonym, not an algorithm change.

### 3.3 1.4.1 aliases vs 2.x removal

1.4.1 already exposes 2.x names as aliases (`ornSegmentSchema()` delegates to `serviceFormat()`, `getPrefix()` to `getServiceIdentifier()`, `getSegment()` to `getProviso()`, `getSegments()` to `getProvisos()`). The IDP **does not use those aliases today** — it still calls the 1.x names.

2.x **deletes** the 1.x names. After the composer bump the IDP must call only 2.x names. Using aliases on 1.4.1 first is optional and **not** required; this project is a single bump + rename pass.

---

## 4. Dependency graph (target)

```
amtgard/idp-server
  ├── php ^8.4
  ├── amtgard/ork-iam ^2.1
  ├── amtgard/ork-iam-orn-definitions ^2.0   (requires ork-iam ^2.1; register.php for ORK/Attendance)
  ├── src/register_orn_definitions.php       (IdpClaim / IdpRequirement on ServiceCatalog::Idp)
  └── league/oauth2-server, slim, …          (untouched)

amtgard-idp-php-client (sibling, unchanged by this migration)
  └── already on ork-iam ^2.1                ← wire-compatible with this IDP before and after
```

`ork-iam` **main** = 2.x through **v2.1.1**. Maintenance **1.x** branch retains **v1.4.1**.

---

## 5. What stays stable vs what changes

### Stable (product / wire)

- Route set, OpenAPI operationIds, JSON property names
- ORN string shape and built-in prefix values (`Idp`, `ORK`, `Attendance`, …)
- JWT claim set (`iat`, `sub`, `iss`, `orkid`, `orkuser`, `email`, `policy`, `challenge`, `exp`, `aud`, `client_metadata`)
- `Policy::toJson()` bytes for the same stored rows (sorted JSON array of ORNs)
- `UserAuthority` admin ORN `"Idp:0::::IDP/EditClient"`
- Default service-format slot **values**: `Configuration`, `Game`, `Kingdom`, `Park`
- `BuiltInOrkPolicyServices::serviceNames()` string list (enum **values** identical on `ServiceCatalog`)
- OAuth, Apple login, VERSION/build-id, management CRUD **behavior**

### Changes (PHP only)

- `OrkServices` → `ServiceCatalog` (`Amtgard\IAM\Catalog\ServiceCatalog`)
- `serviceFormat()` overrides → `ornSegmentSchema()` (visibility: 2.x parent is `public` — see detailed-design)
- `toOrkServices()` → `toCatalogEntry()`
- `getServiceIdentifier()` → `getPrefix()`
- `getProviso()` / `getSegmentValue()` (tests) → `getSegment()` / `getValue()`
- `OrnClassMap::validateCustomServiceName()` → `validateCustomPrefix()`
- `composer.json` pins as above
- PHPDoc / typehints `list<OrkServices|string>` → `list<ServiceCatalog|string>`
- `use Amtgard\IAM\Orn\OrnSegmentLabel` → `Amtgard\IAM\ORN\OrnSegmentLabel` (2.x namespace case)

### Explicit non-goals (architecture)

| Temptation | Verdict |
|------------|---------|
| Adopt `PolicyDocument` for JWT minting | **Reject** — not a capability project; `toJson()` already is the wire |
| Adopt `ClaimBuilder` in Client IAM | **Reject** — repository already concatenates stored strings |
| Rename HTTP `provisos` → `segments` | **Reject** — wire-agnostic |
| Rename `BuiltInOrkPolicyServices` / `IamServiceFormatParser` class names | **Reject** — optional later; not required to compile on 2.x |
| Move IDP subclasses into orn-definitions | **Reject** — out of scope |

---

## 6. Independent vs coordinated cutover

| Scenario | php-client 2.x, IDP still 1.4.1 | Both on 2.x |
|----------|---------------------------------|-------------|
| OAuth + resource GETs | Already fine | Fine |
| Client IAM writes (`provisos` / `resource` / `service_format`) | Already fine | Fine |
| JWT `policy` array consumed by client | Fine (strings) | Fine |
| Sharing in-memory `Claim` objects across processes | Not supported | Still not supported |

**Recommendation:** implement this server rename when ready; do not wait on or block a php-client release. Call out in the PR that wire compatibility is the acceptance bar.

---

## 7. Non-architectural reminders

- Touch `templates/api.md` only if a 1.x **PHP type name** (`OrkServices`) would confuse operators — keep every JSON key and ORN example
- Do not edit `.cursor/plans`
- Do not “fix” unused imports (`ClaimFactory` in `Jwt.php` / `ApiController`) unless the bump forces it
- Proof of no-op is **bytes**: JWT policy JSON, ORN lists, `service_format` encode — see [milestones.md](./milestones.md)
