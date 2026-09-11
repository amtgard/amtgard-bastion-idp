# Style, patterns, deduplication — implementation plan

**Baseline commit:** `f2a6c19` on branch `style-refactor` (line numbers may still reference pre-merge `b332a44`; re-anchor when touching files).

**Milestone status:** see [style-refactor-milestones.md](./style-refactor-milestones.md).

**Audience:** implementation agent. Run `composer test`, `composer stan`, and `composer cs` after each milestone unless a task says otherwise.

**Explicit non-goals**

- Do not adopt Symfony Cache (remove unused `symfony/cache` dependency only).
- Do not change PVH wire semantics or HTTP validate/userinfo contracts unless a task explicitly says so.

---

## Conventions (apply to all new/refactored code)

| Rule | Detail |
|------|--------|
| **Builder over constructors** | Prefer `Amtgard\Traits\Builder\Builder` (+ `Getter` where needed) for new services/value types that may gain optional dependencies later (`AuthorizedClients`, `PubSubQueueHandle`, `OAuthServerConfiguration` are models). PHP-DI may still resolve leaf services via constructor promotion when they are not extended. |
| **`declare(strict_types=1)`** | Every `src/**/*.php` file (see Milestone A inventory). |
| **`final`** | Default for classes not designed for inheritance (utilities, middleware collaborators, services). Keep base classes (`BaseAuthController`, `LocalIdpAuthMiddleware`) non-final. |
| **Constructor promotion** | Use promoted properties; drop redundant field assignment (e.g. `CachedJwtLocalIdpAuthMiddleware` L22–38). |
| **`Optional`** | Prefer over ad-hoc null checks at I/O boundaries (HTTP, Redis parse, repository lookups). |
| **Enums over booleans** | Replace unclear `bool` parameters with enums (see Milestone B). Keep booleans only when semantically obvious (e.g. backoff “had work”). |
| **Logging** | Structured context arrays; increase **info** / **debug** on auth, PVH, OAuth, IAM paths (not only `notice`/`warning`). |
| **Ternaries** | Extract to named helpers (especially PVH/JWT claim extraction). |
| **JWT library** | **Firebase `firebase/php-jwt` only** (lighter, already used for mint). Remove Lcobucci usage from application code. |

---

## Milestone A — `strict_types` + `final` (legacy sweep)

**Goal:** All production PHP under `src/` uses strict types; mark leaf classes `final` where safe.

### A1 — Files missing `declare(strict_types=1)` (add as line 2 after `<?php`)

| File |
|------|
| `src/Controllers/Client/AppleAuthController.php` |
| `src/Controllers/Client/BaseAuthController.php` |
| `src/Controllers/Client/DiscordAuthController.php` |
| `src/Controllers/Client/FacebookAuthController.php` |
| `src/Controllers/Client/GoogleAuthController.php` |
| `src/Controllers/Management/ManagementController.php` |
| `src/Controllers/Resource/LowLatencyController.php` |
| `src/Controllers/Resource/ResourcesController.php` |
| `src/Controllers/Server/OAuth2ServerController.php` |
| `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php` |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` |
| `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` |
| `src/Middleware/ManagementMiddleware.php` |
| `src/Models/AmtgardIdpJwt.php` |
| `src/Models/OAuthServerConfiguration.php` |
| `src/Models/Orn/ClientApplicationClaim.php` |
| `src/Models/Orn/ClientApplicationFormat.php` |
| `src/Models/Orn/IdpClaim.php` |
| `src/Models/Orn/IdpFormat.php` |
| `src/Models/Orn/IdpRequirement.php` |
| `src/Persistence/Client/Entities/UserEntity.php` |
| `src/Persistence/Client/Entities/UserLoginEntity.php` |
| `src/Persistence/Client/Entities/UserOrkProfileEntity.php` |
| `src/Persistence/Client/Repositories/UserLoginRepository.php` |
| `src/Persistence/Common/Repositories/JwtChallenge.php` |
| `src/Persistence/Common/Repositories/UserPolicy.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthAccessToken.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthAuthCode.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthClient.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthRefreshToken.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthScope.php` |
| `src/Persistence/Server/Entities/OAuth/OAuthUser.php` |
| `src/Persistence/Server/Entities/Repository/AccessToken.php` |
| `src/Persistence/Server/Entities/Repository/AuthCode.php` |
| `src/Persistence/Server/Entities/Repository/Client.php` |
| `src/Persistence/Server/Entities/Repository/Grant.php` |
| `src/Persistence/Server/Entities/Repository/RefreshToken.php` |
| `src/Persistence/Server/Entities/Repository/Scope.php` |
| `src/Persistence/Server/Entities/Repository/UserClientAuthorization.php` |
| `src/Persistence/Server/Entities/SerializationTrait.php` |
| `src/Persistence/Server/Repositories/AccessTokenRepository.php` |
| `src/Persistence/Server/Repositories/AuthCodeRepository.php` |
| `src/Persistence/Server/Repositories/ClientRepository.php` |
| `src/Persistence/Server/Repositories/RefreshTokenRepository.php` |
| `src/Persistence/Server/Repositories/ScopeRepository.php` |
| `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php` |
| `src/Services/OrkService.php` |
| `src/Utility/AuthorizedClients.php` |
| `src/Utility/Constants.php` |
| `src/Utility/Jwt.php` |
| `src/Utility/PubSubQueueHandle.php` |
| `src/Utility/UserAuthority.php` |
| `src/Utility/Utility.php` |

**Acceptance:** `rg -L 'declare\(strict_types' src --glob '*.php'` returns empty. Fix any strict-types fallout in the same milestone.

### A2 — Promote `final` on leaf classes (non-exhaustive; verify no subclass in repo before marking)

| File | Lines (class decl.) | Notes |
|------|---------------------|--------|
| `src/Utility/Jwt.php` | L16 | static utility |
| `src/Utility/Utility.php` | L11 | static façade (later replace with DI in Milestone F) |
| `src/Models/AmtgardIdpJwt.php` | L17 | after DI fix |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` | L21 | |
| `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` | L25 | |
| `src/Middleware/ManagementMiddleware.php` | (class line) | |
| `src/Controllers/Resource/LowLatencyController.php` | L17 | |
| `src/Services/OrkService.php` | (class line) | |
| `src/Utility/AuthorizedClients.php` | (class line) | |
| `src/Utility/UserAuthority.php` | (class line) | |
| `src/Utility/PubSubQueueHandle.php` | L8 | |

**Do not** mark `final`: `BaseAuthController`, `LocalIdpAuthMiddleware`, `CachedJwtLocalIdpAuthMiddleware`, OAuth League repository classes, AARO entities.

---

## Milestone B — Enums instead of boolean API flags

### B1 — Confidential client IAM requirement

| Location | Lines | Change |
|----------|-------|--------|
| `src/Utility/Security/ConfidentialClientAuthenticator.php` | L21 `authenticate(..., bool $requireIamService)` | Introduce `enum ConfidentialClientAuthMode { case CredentialsOnly; case RequireIamService; }`. Replace bool param. |
| `src/Middleware/ConfidentialClientAuthMiddleware.php` | L27–28 | Pass `RequireIamService`. |
| `src/Middleware/ConfidentialClientCredentialMiddleware.php` | L26–27 | Pass `CredentialsOnly`. |

**Acceptance:** No `bool` param on `authenticate()`; tests updated.

### B2 — Post-login redirect policy

| Location | Lines | Change |
|----------|-------|--------|
| `src/Controllers/Client/BaseAuthController.php` | L26–50 `finalizeAuthorization(..., bool $isNewUser)` | Introduce `enum AuthorizationFinalizeRedirect { case NewUserProfile; case ReturningUserWithStoredRedirect; }` (or similar). Call sites in Google/Facebook/Discord/Apple callbacks pass enum, not bool. |
| Social controllers | e.g. `GoogleAuthController.php` L96–112, `FacebookAuthController.php` L92–108, `DiscordAuthController.php` L99–128, `AppleAuthController.php` (callback) | Replace `$isNewUser` bool with enum value when calling `finalizeAuthorization`. |

**Keep:** `CallConsumersBackoff::next(bool $hit)` — semantic “processed message this iteration” is acceptable.

---

## Milestone C — JWT: Firebase-only + claim helpers

### C1 — Consolidate verify/parse on Firebase

| Location | Lines | Change |
|----------|-------|--------|
| `src/Utility/Jwt.php` | L7–12, L74–98 | Remove Lcobucci imports and `Configuration` validation. Use `Firebase\JWT\JWT::decode` with `Firebase\JWT\Key` (public key) + leeway if needed; map exceptions to `null` / failed validation consistent with today. |
| `src/Utility/Jwt.php` | L109–120 | Prefer decode via Firebase for payload extraction where possible, or keep minimal base64url parse if decode is too heavy for unverified peek — document choice in class docblock. |
| `src/Models/AmtgardIdpJwt.php` | L14, L57–61 | Already Firebase; no Lcobucci. |
| `config/container.php` | L242 `JWT::$leeway` | Keep for Apple provider; ensure global leeway still appropriate for shared `Jwt` helper. |
| `composer.json` | L13, L25 | Keep `firebase/php-jwt`. Do **not** add `lcobucci/jwt` as direct require. |
| Tests | See Milestone H | Replace Lcobucci test token builders with Firebase encode or shared factory. |

**Acceptance:** `rg 'Lcobucci' src/` is empty. PHPUnit green.

### C2 — Extract ternaries / duplicate claim logic

Add to `src/Utility/Jwt.php` (after L148 region):

| New helper | Replaces duplicated logic at |
|------------|------------------------------|
| `emailClaim(array $payload): string` | `CachedJwtLocalIdpAuthMiddleware.php` L69; `ClientRestrictedAuthMiddleware.php` L76; `LowLatencyController.php` L154 |
| `presentedPvhContext(array $payload): array{presented: ?string, fatPolicyHash: ?string}` | Middleware L74–75, L81–82; `PvhGate.php` L33–34; `LowLatencyController.php` L112–113 |

Implement fat hash branch via helper method, **not** inline ternary at call sites.

**Acceptance:** `rg "presentedPvhClaim\(\$payload\) === null \?" src/` empty.

---

## Milestone D — PVH auth deduplication + strategy

### D1 — New collaborator (Builder-based)

**Create** `src/Utility/Pvh/PvhAuthorizationGate.php` (name flexible):

- Dependencies via **Builder**: `RedisCacheRepository`, optional `LoggerInterface`.
- Method e.g. `evaluateAndSeed(string $userUuid, string $aud, array $payload): PvhGateOutcome` enum: `Proceed`, `StaleToken`, `Unauthorized` (+ internal seed side effect on `Miss`).
- Uses `PvhGate::evaluate`, `Jwt::presentedPvhContext`, `Jwt::emailClaim`, `PvhGate::missSeedRecord`.

Wire in PHP-DI (`config/container.php`) if not static.

### D2 — Refactor middleware (delete duplicate blocks)

| File | Lines to replace | Action |
|------|------------------|--------|
| `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php` | L57–81 | Delegate to D1; add **debug** logs for branch taken (user_uuid, aud, access). |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` | L64–88 | Same D1 helper. |
| `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php` | L27–38 | Constructor promotion only; drop duplicate assignments L35–38. |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` | L28–39 | Constructor promotion. |

### D3 — Extract shared OAuth access-token fallback

| File | Lines | Action |
|------|-------|--------|
| `CachedJwtLocalIdpAuthMiddleware.php` | L84–98 | Move to trait or `src/Utility/Security/OAuthAccessTokenFallback.php` shared with |
| `ClientRestrictedAuthMiddleware.php` | L91–103 | Same helper (ResourceServer validate → session user/client → proceed). |

### D4 — `LowLatencyController::validate`

| File | Lines | Action |
|------|-------|--------|
| `src/Controllers/Resource/LowLatencyController.php` | L89–170 | Use D1 for evaluate/seed; keep HTTP-specific logging at **info**/**debug**; unauthorized paths stay `PvhGate::writeUnauthorized`. |

**Acceptance:** PVH middleware tests + `LowLatencyControllerTest` green; no behavioral change to status codes.

---

## Milestone E — PVH Redis projection dedup

### E1 — Single factory for `PvhCacheRecord` from generation row

| Location | Lines | Change |
|----------|-------|--------|
| `src/Utility/PvhCacheRecord.php` | after L103 | Add `fromGeneration(UserJwtGeneration $row, string $email): self` (or static on new `PvhCacheProjection` Builder class). |
| `src/Models/AmtgardIdpJwt.php` | L64–72 | Use factory. |
| `src/Services/JwtPvhRefreshService.php` | L71–77 | Use factory. |

### E2 — Optional + logging in refresh service

| Location | Lines | Change |
|----------|-------|--------|
| `src/Services/JwtPvhRefreshService.php` | L38–46 | `Optional::ofNullable($user)->orElseGet` → return `UserMissing`; add **debug** on entry. |
| `src/Services/JwtPvhRefreshService.php` | L49–58 | Optional chain for existing row hash compare. |

---

## Milestone F — DI, Builder, EntityManager bootstrap

### F1 — Inject `AuthorizationJwtAssembler` (no manual `new`)

| Location | Lines | Change |
|----------|-------|--------|
| `src/Models/AmtgardIdpJwt.php` | L19–41 | Remove manual assembler construction. Inject `AuthorizationJwtAssembler` + `RedisCacheRepository` via promoted constructor **or** convert `AmtgardIdpJwt` to Builder pattern if extending mint paths is anticipated. |
| `config/container.php` | after L112 | Ensure `AuthorizationJwtAssembler` autowires (already `final` with promoted deps). |

### F2 — Replace fake `EntityManager` first-parameter hack

**Problem:** `config/container.php` L167–172 documents EM-first ctor ordering for autowiring.

| File | Lines | Current |
|------|-------|---------|
| `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` | L27–28 | Unused `EntityManager $entityManager` |
| `src/Middleware/OAuthAccessTokenElevationMiddleware.php` | L28 | Unused EM |
| Social auth controllers | `AppleAuthController.php` L26, `DiscordAuthController.php` L26, `FacebookAuthController.php` L25, `GoogleAuthController.php` L26 | Unused EM |
| `src/Controllers/Client/AuthController.php` | L34 | Unused EM |
| `src/Controllers/Client/ConnectController.php` | L32 | May use EM — verify before removal |
| `src/Controllers/Management/ManagementController.php` | L32 | verify |
| `src/Controllers/Server/OAuth2ServerController.php` | L45 | verify |
| `src/Services/RegistrationService.php` | L20 | verify |
| `src/Middleware/LocalAdminUserMiddleware.php` | L24 | uses EM? verify |

**Proposed fix (validate with container integration test):**

1. Add `tests/Config/ContainerResolutionOrderTest.php`:
   - Boot `config/bootstrap.php` container.
   - Resolve, in one test method: `ConfidentialClientBasicAuthMiddleware`, `ClientRepository`, `UserRepository`, `AuthorizationJwtAssembler`, `AmtgardIdpJwt`.
   - Assert not null; optionally assert `EntityManager::getManager()` is configured (same instance as container EM).
2. Remove unused `EntityManager` parameters from classes that do not use `$em`.
3. If PHP-DI ever resolves repositories before EM, add explicit definitions (already pattern at `container.php` L102–112) rather than ctor hacks.

**Acceptance:** New test passes; removed EM params; full PHPUnit green.

### F3 — Session / current-user DI seams

| Location | Lines | Change |
|----------|-------|--------|
| `src/Utility/Utility.php` | L13–31 | Introduce `CurrentUserResolver` (Builder + interface) wrapping session + `UserRepository`; inject into controllers instead of static calls. |
| `src/Controllers/Resource/ResourcesController.php` | L103–105 `Utility::getAuthenticatedUser()` | Inject resolver. |
| `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php` | L106–107 | Route session writes through `SessionStorage` or expand `LoginSession` (see `src/Utility/Security/SessionStorage.php`, `LoginSession.php`). |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` | L107–108 | Same. |

**Acceptance:** Unit tests can mock resolver; no direct `Utility::getAuthenticatedUser` in new code paths.

---

## Milestone G — Confidential Basic auth dedup

| Location | Lines | Change |
|----------|-------|--------|
| `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` | L36–48 | Use `HttpBasicCredentialsParser::fromAuthorizationHeader` (`src/Utility/Security/HttpBasicCredentialsParser.php` L19–48). |
| Same | L50–58 | After parse: allow-list check → `ClientRepository::validateClient` (mirror `ConfidentialClientAuthenticator.php` L32–35). Consider small `AllowListedConfidentialClientAuthenticator` composing parser + allow list + repository. |
| `src/Utility/Security/ConfidentialClientAuthenticator.php` | L32–35 | Deduplicate repeated `$credentials->get()` into local variable. |

Add **info**/**debug** structured logs matching `ConfidentialClientAuthenticator` style.

---

## Milestone H — Client IAM controller validation dedup

| Location | Lines | Change |
|----------|-------|--------|
| `src/Controllers/Resource/ClientResourcesController.php` | L427–438 `hasNonEmptyPublicId`, `hasPositiveInteger` | Delete; use `ClientResourcesRequestResolver` (`src/Utility/Client/ClientResourcesRequestResolver.php` L23–48) only. |
| `src/Controllers/Resource/ClientResourcesController.php` | L372–380 `requireUser` | Keep; ensure it uses resolver Optional exclusively. |

---

## Milestone I — Social OAuth template + repository dedup

### I1 — Callback template

**Create** `src/Utility/Security/OAuthSocialCallbackHandler.php` (Builder):

- Accepts provider name, callables: fetch token, map user data, resolve user, resolve login.
- Shared: `OAuthCallbackValidator::validate`, try/catch, `ScriptAlertResponse`, structured logs (**info** sign-in start, **debug** provider payload).

| File | Lines (callback body) | Refactor |
|------|------------------------|----------|
| `src/Controllers/Client/GoogleAuthController.php` | L73–120 | Thin wrapper |
| `src/Controllers/Client/FacebookAuthController.php` | L66–116 | Thin wrapper |
| `src/Controllers/Client/DiscordAuthController.php` | L71–130 | Thin wrapper; remove `createUserFromGoogleData` hack L109–116 — use dedicated mapper |
| `src/Controllers/Client/AppleAuthController.php` | callback method | Thin wrapper |

### I2 — Redirect init session keys

Extract shared “store redirect + jwtpublickey” for Google/Discord/Apple (Facebook lacks it — L46–56 vs `GoogleAuthController.php` L57–59).

### I3 — User repository

| Location | Lines | Change |
|----------|-------|--------|
| `src/Persistence/Client/Repositories/UserRepository.php` | L31–70 | Single `createUserFromOAuthProfile(email, first, last)`; remove duplicate `setUserId` in L50–51, L57–58, L68–69. |
| Provider-specific methods | L48–70 | Delegate to shared method with field mapping at controller/strategy layer. |

### I4 — User login repository

| Location | Lines | Change |
|----------|-------|--------|
| `src/Persistence/Client/Repositories/UserLoginRepository.php` | L112–155 | `createLoginFromProvider(string $type, UserEntity $user, string $providerId, string $avatarUrl, $token, callable $refreshTokenAccessor)` |
| `updateLoginTokens` | L157–171 | Already uses callable — reuse |

---

## Milestone J — Large class decomposition

Execute in sub-PRs; each extraction keeps routes stable.

### J1 — `OAuth2ServerController` (~469 lines)

| File | Lines (indicative) | Extract |
|------|-------------------|---------|
| `src/Controllers/Server/OAuth2ServerController.php` | L67–76 token | `OAuthTokenAction` |
| | L78+ approve | `OAuthApproveAction` |
| | authorize flow | `OAuthAuthorizeAction` |
| Session `authRequest` serialize | grep `authRequest` in file | `OAuthSessionAuthRequestStore` |

Use Builder for new action classes.

### J2 — `ResourcesController` (~534 lines)

| File | Lines | Extract |
|------|-------|---------|
| `src/Controllers/Resource/ResourcesController.php` | L101–112 `getJwt` | stays; middleware already split |
| | userinfo handler | `ResourcesUserinfoController` or service |
| | ORK profile sections | delegate to `OrkService` / existing repos with thinner controller |

### J3 — `ClientResourcesController` (~515 lines)

| File | Lines | Extract |
|------|-------|---------|
| `src/Controllers/Resource/ClientResourcesController.php` | L57–120+ IAM mutations | `ClientIamPolicyService` (Builder) |
| | metadata endpoints | `ClientIamMetadataService` |
| | L457–514 service format | keep validator/parser utilities |

### J4 — `UserPolicyClaimRepository` (~297 lines)

| File | Lines | Extract |
|------|-------|---------|
| `src/Persistence/Common/Repositories/UserPolicyClaimRepository.php` | L57–95 writes | `UserPolicyClaimWriter` |
| | L41–55, L97–128 reads | `UserPolicyClaimReader` |
| | L276–296 validation | shared `ClaimOrnValidator` |

### J5 — Worker message value object

| File | Lines | Change |
|------|-------|--------|
| `bin/jwt-pvh-worker.php` | L53–66, L83–87 | `PvhQueueMessage::fromJson(string $message): Optional` |
| `src/Persistence/Server/Repositories/RedisCacheRepository.php` | L83–87 publish payload | reuse same shape encoder |

---

## Milestone K — JSON HTTP helpers + queue handle dedup

### K1 — JSON responses

| Location | Lines | Unify |
|----------|-------|-------|
| `src/Utility/PvhGate.php` | L90–94 | Shared `JsonResponseBody` or trait used by |
| `src/Controllers/Resource/ClientResourcesController.php` | L446–454 | same |
| `src/Controllers/Resource/LowLatencyController.php` | L194–195 | same |

### K2 — Queue handles

| File | Lines | Change |
|------|-------|--------|
| `src/Utility/PubSubQueueHandle.php` | L8–11 | Generalize to `QueueHandle` Builder or shared trait |
| `src/Utility/PvhQueueHandle.php` | L10–15 | Alias/config-only subclass OR single class with two DI entries in `container.php` L265–296 |

---

## Milestone L — Dependencies & tests

### L1 — Composer cleanup ✅

| File | Lines | Change |
|------|-------|--------|
| `composer.json` | L25 `symfony/cache` | Remove if no references (confirmed: no `src/` usage). Run `composer update` and verify Slim still resolves. |
| `composer.json` | require | Add direct `"jedibc/optional": "^…"` matching lockfile version. |

### L2 — Test support dedup

Create `tests/Support/FirebaseJwtTestFactory.php` (or similar):

| Consumer tests (replace Lcobucci blocks) | Approx lines |
|-------------------------------------------|--------------|
| `tests/Utility/JwtTest.php` | L161–165+ |
| `tests/Controllers/LowLatencyControllerTest.php` | L429–464 |
| `tests/Middleware/CachedJwtLocalIdpAuthMiddlewareTest.php` | L258–287 |
| `tests/Middleware/ClientRestrictedAuthMiddlewareTest.php` | L190–194 |
| `tests/Middleware/OAuthAccessTokenElevationMiddlewareTest.php` | L179–206 |

---

## Milestone M — Logging pass (cross-cutting)

Add structured **debug**/**info** (with `user_uuid`, `aud`, `client_id` where applicable):

| Area | Files | Notes |
|------|-------|-------|
| PVH gate branches | D1, `LowLatencyController.php` L119–160 | complement existing `notice` |
| JWT validate | `Jwt.php` after Firebase migration | failed signature reason at debug |
| Social OAuth | I1 handler | provider, email hash/id (not raw PII if policy forbids) |
| IAM client API | `ClientResourcesController.php` mutation methods L57–120 | client id, idp_user_id |
| `BaseAuthController.php` | L28–52 | replace string concat logs with context arrays |

Consider lowering default log level in dev: `config/container.php` L72 (`Logger::NOTICE` when not debug) — optional **info** in production via env if desired (product decision).

---

## Milestone N — `OrnClaimRegistry` table-driven extension

| File | Lines | Change |
|------|-------|--------|
| `src/Utility/OrnClaimRegistry.php` | L27–43 | Replace nested ifs with map: built-in catalog → no-op; registered → no-op; else register `ClientApplicationClaim`. |

---

## Suggested execution order

1. **L1** (composer) + **A1/A2** (strict/final) — mechanical, low risk.  
2. **C1/C2** (Firebase JWT + helpers) + **H** (test factory).  
3. **D + E** (PVH dedup) — highest regression sensitivity; run PVH/validate tests.  
4. **G** (Basic auth).  
5. **B** (enums).  
6. **F** (DI + EM test).  
7. **I** (OAuth template).  
8. **J** (splits), **K**, **M**, **N** as capacity allows.

---

## Verification checklist (agent)

- [ ] `composer test`
- [ ] `composer stan`
- [ ] `composer cs`
- [ ] Manual smoke: GET `/resources/validate`, GET `/resources/userinfo`, GET `/resources/jwt`, client IAM 401/204 paths
- [ ] `bin/jwt-pvh-worker.php` still consumes queue messages (unit/integration as available)

---

## Line-number index (quick reference)

| Symbol | File | Lines |
|--------|------|-------|
| PVH middleware duplicate | `CachedJwtLocalIdpAuthMiddleware.php` | 57–81, 84–98, 106–107 |
| PVH middleware duplicate | `ClientRestrictedAuthMiddleware.php` | 64–88, 91–103, 107–108 |
| Validate endpoint | `LowLatencyController.php` | 89–170, 194–195 |
| Manual assembler | `AmtgardIdpJwt.php` | 32–41, 64–72 |
| Lcobucci verify | `Jwt.php` | 7–12, 74–98 |
| Basic auth duplicate | `ConfidentialClientBasicAuthMiddleware.php` | 36–48 |
| Bool IAM flag | `ConfidentialClientAuthenticator.php` | 21, 51–57 |
| Bool new user redirect | `BaseAuthController.php` | 26–50 |
| Resolver duplicate | `ClientResourcesController.php` | 427–438 |
| EM bootstrap comment | `config/container.php` | 167–172 |
| Redis PVH dup | `JwtPvhRefreshService.php` | 71–77 |
| Worker JSON parse | `bin/jwt-pvh-worker.php` | 53–66 |
