# IDP observability instrumentation checklist

Anchored to `HEAD` **`9980cf5`** (`9980cf53281fcd64828d0ef05b2f33f360ae67c1`). Re-check line numbers after any commit.

**Do not implement from this file.** It is a spike map of every deny, catch, Optional blank, 4xx, silent fallback, and worker path that should emit structured logs.

## How to use this list

- Format: `- [ ] path:Lstart-Lend — level — rationale — context keys`
- **Never log secrets:** passwords, `client_secret`, Bearer/JWT raw, CSRF tokens, `link_token` JWT, ORK `Token`, cookies, Authorization header values, completion JWT, PEM material.
- **Safe context keys (preferred):** `request_id`, `path`, `method`, `client_id` / `aud`, `user_uuid`, `login_id` (numeric), `outcome`, `reason`, `http_status`, `exception_class`, `exception_message` (sanitized), `provider`, `email_sha256` (already used in social handler), `mundane_id`, `jti` (handoff tokens only — not session IDs).
- **Production filter:** `config/container.php:L78` sets Monolog to **`DEBUG` only when `APP_DEBUG=true`**, otherwise **`NOTICE`**. That means **`debug` and `info` are dropped in production today.** Prefer `notice` for security/auth outcomes you need in prod; keep `debug` for verbose happy-path only.

## Global: correlation (do this once, then reuse)

Every HTTP deny path below is more useful if a request-scoped ID exists. There is **no** correlation middleware today.

- [ ] `src/Middleware/SessionMiddleware.php:L17-L28` — debug — session start is silent; attach `request_id` to request attribute and session — `request_id`, `session_started` (bool), `session_handler` (`redis`|`files`)
- [ ] `config/middleware.php:L13-L53` — notice — Slim error middleware is wired with logger but no request ID; inject correlation middleware first (LIFO last-added) — `request_id`
- [ ] `config/container.php:L72-L86` — notice — document/change default level so `info` auth denials are not invisible in prod; processors for `request_id` / `service` — `channel`, `min_level`

---

## M1 — HTTP middleware + correlation

**Count: 28** · Highest traffic gates. Several inject `LoggerInterface` and never use it.

### Session / body / CORS (zero logging today)

- [ ] `src/Middleware/JsonBodyParserMiddleware.php:L20-L25` — warning — `json_decode` failure is swallowed; request continues with empty/previous body — `path`, `method`, `json_error` (from `json_last_error_msg()`)
- [ ] `src/Middleware/SessionMiddleware.php:L19-L23` — warning — `SessionStorage::startSession()` can fall back to files with no log — `session_handler`, `fallback` (bool)
- [ ] `src/Utility/Security/SessionStorage.php:L42-L44` — warning — Redis configured but unreachable; silent file fallback — `host_set` (bool, not hostname if sensitive), `reason` (`extension_missing`|`connect_failed`)
- [ ] `src/Utility/Security/SessionStorage.php:L87-L108` — debug — Redis probe fail / catch-all — `reason` (`no_ext`|`connect`|`throwable`)
- [ ] `src/Middleware/CorsMiddleware.php:L16-L18` — debug — OPTIONS short-circuit (noise; sample) — `path`, `origin_present` (bool)

### Local / browser session auth (logger unused)

- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L42-L45` — info — Bearer present on session-only path → 401 (anti-confused-deputy) — `path`, `reason` (`bearer_not_allowed`)
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L49-L50` — warning — session `client_id` not in authorized clients — `path`, `client_id`, `reason` (`client_not_authorized`)
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L64-L66` — info — OAuth resource-server exception swallowed; fall through to login redirect — `path`, `oauth_error` (League message, no token)
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L68-L79` — info — no session user and no valid token → 302 `/auth/login` — `path`, `reason` (`unauthenticated_redirect`)

### Cached JWT + client-restricted (partial debug only; invisible in prod)

- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L39-L40` — info — missing/invalid Authorization JWT (`orElseThrow`) — `path`, `reason` (`jwt_required`)
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L42-L47` — info — parse / `sub` / `aud` blank (`orElseThrow`) — `path`, `reason` (`jwt_malformed`|`missing_sub`|`missing_aud`)
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L49-L54` — debug — non-authorization payload → OAuth access-token fallback — `path`, `reason` (`oauth_fallback`)
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L61-L68` — notice — **upgrade** existing `debug` so prod sees gate outcome (or rely on `PvhAuthorizationGate` after level fix) — `user_uuid`, `aud`, `access`, `outcome`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L71-L74` — info — `StaleToken` 409 / `Unauthorized` 401 after gate — `user_uuid`, `aud`, `outcome`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L40-L42` — debug — session allow-list short-circuit (happy path) — `client_id`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L44-L47` — info — JWT / parse / `sub` / `aud` `orElseThrow` — `path`, `reason` (`jwt_required`|`jwt_malformed`|`missing_sub`|`missing_aud`)
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L49-L50` — warning — JWT `aud` not in valid clients — `client_id`, `reason` (`client_not_restricted_ok`)
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L53-L58` — debug — OAuth fallback branch — `path`, `reason` (`oauth_fallback`)
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L65-L73` — notice — **upgrade** existing `debug` for prod — `user_uuid`, `aud`, `access`, `outcome`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L75-L78` — info — stale / unauthorized after gate — `user_uuid`, `aud`, `outcome`

### JWT elevation (`GET /resources/jwt`)

- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L35-L43` — info — RS256 authorization JWT presented on elevation endpoint — `path`, `reason` (`auth_jwt_not_accepted`)
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L48-L51` — warning — session `client_id` not authorized — `client_id`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L58-L60` — info — no session and no Bearer — `path`, `reason` (`token_or_session_required`)
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L65-L67` — info — **already logged**; keep; add `client_id` if present — `msg`, `path`

### CSRF (already warning — keep)

- [ ] `src/Middleware/CsrfMiddleware.php:L44-L49` — warning — **already logged**; add `request_id` when available — `path`, `method`

### Management + admin

- [ ] `src/Middleware/ManagementMiddleware.php:L22-L27` — error — `MANAGEMENT_KEY` unset → 500 (comment says “or log”; nothing logs) — `path`, `reason` (`key_unset`)
- [ ] `src/Middleware/ManagementMiddleware.php:L30-L33` — error — key too short → 500 — `path`, `reason` (`key_too_short`) — **do not log key**
- [ ] `src/Middleware/ManagementMiddleware.php:L36-L39` — warning — wrong key → 403 — `path`, `reason` (`key_mismatch`) — **do not log provided key**
- [ ] `src/Middleware/LocalAdminUserMiddleware.php:L31-L49` — warning — missing user / not admin → 302 profile (looks like success to clients) — `path`, `user_uuid_present` (bool), `reason` (`unauthenticated`|`not_admin`)

### Confidential client wrappers (auth logs live in authenticators)

- [ ] `src/Middleware/ConfidentialClientAuthMiddleware.php:L26-L30` — debug — IAM-required gate pass-through (optional; authenticators already log) — `client_id`
- [ ] `src/Middleware/ConfidentialClientCredentialMiddleware.php:L25-L29` — debug — credentials-only gate pass-through — `client_id`
- [ ] `src/Middleware/ConfidentialClientBasicAuthMiddleware.php:L28-L32` — debug — allow-listed Basic pass-through — `path`

**M1 summary: 28** (plus 3 global correlation items above if counted separately → 31)

---

## M2 — OAuth server + social login

**Count: 36**

### `/oauth/authorize`

- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L59-L72` — info — unauthenticated / stale session user → login redirect — `client_id`, `reason` (`login_redirect`|`session_user_missing`)
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L75-L76` — info — consent required — `client_id`, `user_uuid`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L80-L86` — notice — **downgrade/split**: League protocol errors logged as `error` today (invalid_client, etc. are client faults) — `step`, `oauth_error`, `hint`, `http_status`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L99-L108` — error — generic Throwable (renderer logs if exception passed) — `step`, `exception_class`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L148-L165` — debug — consent check false (no prior authorization) — `client_id`, `user_identifier_present` (bool)
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L208-L211` — warning — **already logged**; add `client_id` — `user_uuid`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L228-L232` — notice — **already logged** seed — `user_uuid`, `aud`, `legacy`

### `/oauth/token` (almost silent)

- [ ] `src/Controllers/Server/OAuth/OAuthTokenAction.php:L27-L28` — info — token grant success (sample; high volume) — `grant_type` (from body key only), `client_id` if parsed without secret
- [ ] `src/Controllers/Server/OAuth/OAuthTokenAction.php:L29-L30` — notice — `OAuthServerException` returned with **no log** (invalid_grant, invalid_client, …) — `step`, `oauth_error`, `hint`, `http_status`
- [ ] `src/Controllers/Server/OAuth/OAuthTokenAction.php:L31-L32` — error — covered by renderer; keep — `step`

### `/oauth/approve`

- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L43-L51` — notice — League exception → HTML error, **no logger on this class** — `step`, `oauth_error`, `http_status`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L52-L61` — error — renderer logs only if `$exception` passed (it is) — `step`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L71-L89` — info — user allowed client — `client_id`, `user_uuid`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L82-L84` — warning — allow but missing `sessionUserId` or client entity (authz row not written) — `client_id`, `reason` (`authorize_skipped`)
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L92-L96` — info — user denied / cleared auth request — `client_id` if known
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L99-L116` — warning — `getClientEntity` null → likely fatal on `getName()` — `client_id`

### Error renderer gaps

- [ ] `src/Controllers/Server/OAuth/OAuthFlowErrorRenderer.php:L31-L37` — notice — protocol HTML errors (`$exception === null`) emit **no log** — `step`, `message`, `hint`, `http_status`, `is_protocol_error`
- [ ] `src/Controllers/Server/OAuth/OAuthFlowErrorRenderer.php:L32-L36` — error — **already logged** internals; drop `trace` from default prod or gate behind `APP_DEBUG` — `step`, `exception_class` (not full trace)
- [ ] `src/Controllers/Server/OAuth/OAuthFlowErrorRenderer.php:L54-L57` — error — **already logged**; same trace concern — `step`

### Facade + dead POST

- [ ] `src/Controllers/Server/OAuth2ServerController.php:L58-L61` — warning — `authorizePost` returns empty 200 — `path`, `reason` (`noop_post`)
- [ ] `src/Controllers/Server/OAuth/OAuthSessionAuthRequestStore.php:L17-L26` — warning — `unserialize` of session `authRequest` can fail silently / corrupt — `has_blob` (bool)

### Social OAuth (handler has start/error; validators silent)

- [ ] `src/Utility/Security/OAuthCallbackValidator.php:L13-L16` — info — provider returned `error=` query — `provider`, `oauth_error`
- [ ] `src/Utility/Security/OAuthCallbackValidator.php:L18-L19` — warning — state CSRF fail — `provider`, `reason` (`invalid_state`)
- [ ] `src/Utility/Security/OAuth2StateManager.php:L22-L24` — warning — missing/mismatch state (call site if validator not used) — `reason` (`empty`|`mismatch`)
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L53-L56` — info — validation fail returns HTML **without log** (validator should log) — `provider`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L60-L62` — info — **already logged** start — `provider`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L67` — debug — **already logged** profile (hashed email) — `provider`, `provider_user_id`, `email_sha256`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L75-L80` — error — **already logged** catch — `provider`, `exception_class`
- [ ] `src/Controllers/Client/GoogleAuthController.php:L86-L92` — info — `orElseGet` creates new Google user — `provider`, `email_sha256`, `reason` (`user_created`)
- [ ] `src/Controllers/Client/GoogleAuthController.php:L95-L103` — info — new vs existing login — `provider`, `reason` (`login_created`|`login_updated`)
- [ ] `src/Controllers/Client/DiscordAuthController.php:L85-L88` — warning — Discord email denied (`throw`) — `provider`, `reason` (`email_missing`)
- [ ] `src/Controllers/Client/DiscordAuthController.php:L90-L95` — info — new Discord user — `provider`, `email_sha256`
- [ ] `src/Controllers/Client/DiscordAuthController.php:L97-L106` — info — login create/update — `provider`
- [ ] `src/Controllers/Client/FacebookAuthController.php:L84-L90` — info — new Facebook user — `provider`, `email_sha256`
- [ ] `src/Controllers/Client/FacebookAuthController.php:L92-L101` — info — login create/update — `provider`
- [ ] `src/Controllers/Client/AppleAuthController.php:L95-L98` — warning — Apple email missing (`throw`) — `provider`, `reason` (`email_missing`)
- [ ] `src/Controllers/Client/AppleAuthController.php:L101-L110` — info — email match vs new Apple user — `provider`, `email_sha256`, `reason` (`linked_existing`|`user_created`)
- [ ] `src/Controllers/Client/AppleAuthController.php:L113-L124` — info — login create/update — `provider`

**M2 summary: 36**

---

## M3 — PVH / JWT / validate / worker

**Count: 32** · Best existing coverage; many levels wrong for prod (`debug`/`info`).

### `GET /resources/validate` (already rich)

- [ ] `src/Controllers/Resource/LowLatencyController.php:L91-L92` — debug — missing bearer (via `rejectValidate`) — `reason`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L95-L96` — debug — bad signature — `reason`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L99-L101` — debug — invalid payload — `reason`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L106-L111` — debug — missing sub/aud — `reason`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L114-L119` — debug — bad issuer — `reason`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L125-L130` — debug — missing pvh context — `reason`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L140-L146` — notice — **already logged** current — `user_uuid`, `aud`, `pvh`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L159-L165` — notice — **already logged** miss seed — `user_uuid`, `aud`, `pvh`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L179-L190` — notice — **already logged** stale → `PvhGate::writeStaleToken` — `user_uuid`, `aud`, `presented_pvh`, `current_pvh`, `prev_pvh`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L193-L202` — notice — **already logged** unknown pvh → `writeUnauthorized` — `user_uuid`, `aud`, `presented_pvh`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L208-L212` — notice — **upgrade** `debug` rejects so prod sees 401 reasons — `reason` + existing context
- [ ] `src/Controllers/Resource/LowLatencyController.php:L223` — debug — enqueue side effect (Redis repo already `notice`s) — `user_uuid`, `aud`

### JWT helpers

- [ ] `src/Utility/Jwt.php:L25` — debug — no Bearer (callers often already reject) — `path` if request available
- [ ] `src/Utility/Jwt.php:L33-L66` — debug — `validateJwt` compare false (legacy challenge path) — `reason` (`parse`|`aud`|`iss`|`exp`|`policy`)
- [ ] `src/Utility/Jwt.php:L78-L87` — notice — **upgrade** signature fail from `debug` (prod-invisible) — `reason` (Firebase message only)
- [ ] `src/Utility/Jwt.php:L97-L104` — debug — `validateJwtRequest` blank Optional — `reason` (`missing_bearer`)
- [ ] `src/Utility/Jwt.php:L122` — debug — `parseJwt` not 3 segments — `reason` (`malformed_jwt`)
- [ ] `src/Utility/OAuthKeyMaterial.php:L15-L16` — error — missing PEM env — `env_key`
- [ ] `src/Utility/OAuthKeyMaterial.php:L21-L23` — error — unreadable `file://` key — `env_key` (not path contents)
- [ ] `src/Utility/OAuthKeyMaterial.php:L30-L32` — error — unreadable path key — `env_key`

### Gate + response writers

- [ ] `src/Utility/Pvh/PvhAuthorizationGate.php:L98-L99` — warning — logger optional; **silent if unset** — `user_uuid`, `aud`
- [ ] `src/Utility/Pvh/PvhAuthorizationGate.php:L110-L116` — notice — **upgrade** miss=`info` / else=`debug` so prod sees all outcomes — `user_uuid`, `aud`, `access`, `outcome`
- [ ] `src/Utility/PvhGate.php:L74-L81` — debug — writers themselves are silent (OK if every caller logs); add only if new callers appear — `error`, `http_status`
- [ ] `src/Utility/Pvh.php:L57` — warning — invalid policy hash hex — `reason`
- [ ] `src/Utility/Pvh.php:L110-L124` — warning — encode/validate argument errors — `reason`

### Fallback + assembler

- [ ] `src/Utility/Security/OAuthAccessTokenFallback.php:L29-L32` — info — League exception → 401, **no logger** — `path`, `reason` (`oauth_token_invalid`)
- [ ] `src/Models/AuthorizationJwtAssembler.php:L118-L120` — warning — `applyPvhClaim` early return (no aud) — `user_uuid`, `reason` (`missing_aud`)
- [ ] `src/Models/AuthorizationJwtAssembler.php:L171-L175` — error — **already logged** ORN register fail — `client_id`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L152-L155` — debug — audience from session fallback — `aud_source` (`arg`|`session`)
- [ ] `src/Models/AuthorizationJwtAssembler.php:L184-L185` — debug — login id fallback chain — `login_id_source` (`arg`|`session`|`default_local`|`first_login`|`none`)
- [ ] `src/Models/AmtgardIdpJwt.php:L45-L50` — notice — mint wrote Redis PVH (or skip if assembler/worker covers) — `user_uuid`, `aud`, `has_generation` (bool)
- [ ] `src/Persistence/Common/Repositories/JwtChallenge.php:L17-L18` — warning — `validateChallenge` always `true` (dead/stub) if ever called in prod — `reason` (`always_true`)

### Refresh service + worker (mostly done)

- [ ] `src/Services/JwtPvhRefreshService.php:L40-L43` — debug — **already logged** entry — `user_uuid`, `aud`
- [ ] `src/Services/JwtPvhRefreshService.php:L58-L62` — notice — **already logged** noop — `user_uuid`, `aud`, `pvh`
- [ ] `src/Services/JwtPvhRefreshService.php:L85-L90` — notice — **already logged** rotate — `user_uuid`, `aud`, `pvh`, `prev_pvh`
- [ ] `src/Services/JwtPvhRefreshService.php:L94-L98` — warning — **already logged** user missing (`orElseGet`) — `user_uuid`, `aud`
- [ ] `bin/jwt-pvh-worker.php:L43-L45` — notice — **already logged** start — `queue`
- [ ] `bin/jwt-pvh-worker.php:L53-L58` — error — **already logged** malformed (`Optional::blank`) — `key`
- [ ] `bin/jwt-pvh-worker.php:L63-L67` — notice — **already logged** dequeue — `key`, `user_uuid`, `aud`
- [ ] `bin/jwt-pvh-worker.php:L72-L77` — error — **already logged** job fail + re-publish — `key`, `detail`, `exception_class`
- [ ] `bin/jwt-pvh-worker.php:L83-L89` — debug — idle backoff (optional; noisy) — `sleep_ms`, `processed`
- [ ] `src/Utility/Pvh/PvhQueueMessage.php:L37-L44` — debug — invalid JSON shape → blank (worker already errors) — `reason` (`schema`)
- [ ] `src/Utility/Pvh/PvhQueueMessage.php:L53-L54` — warning — `json_decode` throw → blank — `reason` (`json_exception`)

**M3 summary: 32** (many already implemented; checkboxes = remaining or level upgrades)

---

## M4 — IAM client API (`/resources/client/*`)

**Count: 24** · Success logs only on claim add/delete. All `jsonError` paths are silent.

- [ ] `src/Controllers/Resource/ClientResourcesController.php:L73-L74` — notice — add claim 400 (`InvalidArgumentException`) — `client_id`, `idp_user_id`, `http_status`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L77-L80` — info — **already logged** add 204 — `client_id`, `idp_user_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L124-L125` — notice — delete claim 400 — `client_id`, `idp_user_id`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L128-L131` — info — **already logged** delete 204 — `client_id`, `idp_user_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L201-L202` — notice — metadata upsert 400 — `client_id`, `idp_user_id`, `login_id`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L205` — info — metadata upsert 204 (no log today) — `client_id`, `idp_user_id`, `login_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L237-L239` — info — metadata 404 `orElseGet` — `client_id`, `idp_user_id`, `login_id`, `reason` (`metadata_not_found`)
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L269-L271` — info — metadata delete 204 — `client_id`, `login_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L321-L322` — notice — service format 409 — `client_id`, `http_status`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L436-L438` — notice — **centralize** `jsonError`: one log for all 4xx — `client_id`, `http_status`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L371-L376` — notice — `requireUser` 400/404 Optional blank — `idp_user_id_present` (bool), `http_status`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L417-L422` — notice — `requireLoginForUser` 400/404 — `login_id`, `http_status`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L448-L449` — notice — save service format 400 — `client_id`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L460-L469` — notice — `service_format` validation throws — `reason` (`missing_array`|`empty_after_validate`)
- [ ] `src/Services/ClientIamPolicyService.php:L21-L35` — debug — addClaim (controller can own) — `client_id`, `user_uuid`
- [ ] `src/Services/ClientIamMetadataService.php:L46-L47` — debug — get returns null — `client_id`, `login_id`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L31-L32` — debug — idempotent add no-op — `user_db_id`, `service`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L65-L66` — notice — `orElseThrow` missing `client_id` for 3p claim — `service`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L116-L119` — notice — claim cap exceeded — `user_db_id`, `client_db_id`, `count`
- [ ] `src/Persistence/Common/Repositories/ClaimOrnValidator.php:L16-L21` — notice — empty/too-long ORN parts — `label`
- [ ] `src/Persistence/Common/Repositories/ClaimOrnValidator.php:L28-L31` — notice — parse fail rethrow — `service` (not full ORN if huge)
- [ ] `src/Utility/ClientMetadata/ClientMetadataEncodingRegistry.php:L34-L35` — notice — bad encoding — `encoding`
- [ ] `src/Utility/ClientMetadata/Base64MetadataEncodingStrategy.php:L18-L32` — notice — base64/JSON object validation — `reason`
- [ ] `src/Utility/ClientMetadata/MetadataSizeGuard.php:L13-L16` — notice — metadata too large — `bytes`, `max_bytes`
- [ ] `src/Utility/ClientMetadata/JsonObjectMetadataEncodingStrategy.php:L19` — notice — metadata not JSON object — `reason`
- [ ] `src/Utility/IamServiceFormatParser.php:L17-L29` — notice — bad format JSON / empty slots — `reason`
- [ ] `src/Utility/IamServiceFormatValidator.php:L11-L12` — debug — blank format → null (default layout) — `reason` (`blank`)

**M4 summary: 24** (last three are extra; treat as 27 if all validators included — **canonical count 27**)

---

## M5 — Persistence, Redis, ORK, workers, registration

**Count: 31**

### Policy empty fallbacks (already `error`)

- [ ] `src/Persistence/Common/Repositories/UserPolicy.php:L25-L30` — error — **already logged** empty policy — add `user_uuid` if available — `detail`
- [ ] `src/Persistence/Common/Repositories/UserPolicy.php:L38-L43` — error — **already logged** encode fallback `[]` — `detail`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimReader.php:L69-L75` — error — **already logged** empty claims — `user_id`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimReader.php:L144-L151` — error — **already logged** skip malformed claim — `user_id`, `orn`
- [ ] `src/Utility/UserAuthority.php:L30-L35` — error — **already logged** admin eval fail → false — `detail`

### Redis PVH cache

- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L37-L39` — debug — cache miss (high volume; sample) — `user_uuid`, `aud`
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L42` — warning — `fromJson` returned null (corrupt blob) — `user_uuid`, `aud`, `reason` (`corrupt_pvh_json`)
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L68-L78` — notice — logout invalidate (no log today) — `user_uuid`
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L89-L93` — notice — **already logged** enqueue — `user_uuid`, `aud`, `queue`
- [ ] `src/Utility/PvhCacheRecord.php:L71-L76` — warning — JSON exception / non-array — `reason`
- [ ] `src/Utility/PvhCacheRecord.php:L87-L95` — warning — missing required fields — `reason`

### JWT generation + client repo

- [ ] `src/Persistence/Server/Repositories/UserJwtGenerationRepository.php:L31-L32` — debug — no generation row — `user_uuid`, `aud`
- [ ] `src/Persistence/Server/Repositories/UserJwtGenerationRepository.php:L55-L59` — notice — policy hash rotated in MySQL — `user_uuid`, `aud`
- [ ] `src/Persistence/Server/Repositories/ClientRepository.php:L76-L77` — info — unknown OAuth client identifier — `client_id`
- [ ] `src/Persistence/Server/Repositories/ClientRepository.php:L100-L106` — warning — `validateClient` false (`orElse` / bad secret) — `client_id`, `grant_type` — **never secret**
- [ ] `src/Persistence/Server/Repositories/UserLoginClientRepository.php:L33-L41` — debug — JWT metadata `orElse` null — `login_id`, `client_db_id`
- [ ] `src/Persistence/Server/Repositories/UserLoginClientRepository.php:L50-L51` — debug — getMetadata miss — `login_id`, `client_db_id`
- [ ] `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php:L36-L37` — debug — authorize idempotent no-op — `user_identifier`, `client_db_id`
- [ ] `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php:L49-L55` — info — revoke — `user_identifier`, `client_db_id`

### ORK profile persistence

- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L23-L29` — debug — unparseable ORK date → null — `field` (if passed)
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L93-L94` — debug — link idempotent same mundane — `user_db_id`, `mundane_id`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L96-L98` — notice — conflict throw (callers log some) — `user_db_id`, `mundane_id`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L124-L144` — warning — PDO integrity → conflict/idempotent — `sqlstate`, `user_db_id`, `mundane_id`

### User / login lookups (high traffic, silent null)

- [ ] `src/Persistence/Client/Repositories/UserRepository.php:L86-L94` — debug — `findUserByUserId` / `getUserEntityById` null — `user_uuid`
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L36` — debug — no local login for user — `user_db_id`
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L48` — debug — `loginBelongsToUser` `orElse(false)` — `login_id`, `user_db_id`
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L62-L63` — debug — no default login id — `user_db_id`
- [ ] `src/Utility/Security/CurrentUserResolver.php:L23-L32` — debug — session missing or user not found — `reason` (`no_session`|`user_missing`)

### ORK HTTP + link tokens (heavy existing logs)

- [ ] `src/Services/OrkService.php:L27-L28` — error — missing ORK UA/referer at construct — `reason`
- [ ] `src/Services/OrkService.php:L55` — warning — **redact**: logs full effective URI (may contain password query) — `call` only
- [ ] `src/Services/OrkService.php:L65` — warning — **already logged**; drop raw `response` / username if PII-heavy — `username` → `username_sha256`
- [ ] `src/Services/OrkService.php:L68-L70` — error — **already logged** — `exception_message`
- [ ] `src/Services/OrkService.php:L118-L119` — debug — `getParkShortInfo` parkId ≤ 0 early null — `park_id`
- [ ] `src/Services/OrkService.php:L154-L160` — warning — **already logged**; drop `rawBody` from prod — `park_id`, `http_status`
- [ ] `src/Services/OrkLinkTokenService.php:L36-L37` — error — short/missing shared secret — `reason` (`secret_too_short`)
- [ ] `src/Services/OrkLinkTokenService.php:L54-L79` — (mixed) — **already logged** peek failures; normalize expired=`info` vs decode=`info` vs sig=`warning` — `reason`
- [ ] `src/Services/OrkLinkTokenService.php:L99-L102` — warning — **already logged** replay — `jti`
- [ ] `src/Services/OrkLinkTokenService.php:L117-L118` — warning — **already logged** cleanup fail — `msg`

### Registration

- [ ] `src/Services/RegistrationService.php:L29-L30` — notice — validation fail required fields — `reason` (`required`)
- [ ] `src/Services/RegistrationService.php:L32-L33` — notice — invalid email — `reason` (`email_format`)
- [ ] `src/Services/RegistrationService.php:L35-L36` — notice — email already registered — `reason` (`email_taken`), `email_sha256`
- [ ] `src/Services/RegistrationService.php:L38-L40` — info — user+login created — `user_uuid`

### Pub/sub Redis connect

- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L82-L84` — warning — host candidate fail (caught) — `attempt_index`
- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L89-L96` — error — all hosts failed — `host_count` (not secrets)
- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L143-L144` — debug — reachability probe catch — `reason`

**M5 summary: 31** (ORK/link already-logged items included as normalize/redact tasks)

---

## M6 — Error handlers + HTTP exception mapping

**Count: 10**

- [ ] `src/Handlers/ApiAwareErrorHandler.php:L16-L25` — notice — Slim parent logs exceptions; add structured `path`/`http_status`/`exception_class` for `/resources` and `/oauth` — `path`, `method`, `http_status`, `exception_class`
- [ ] `src/Handlers/ApiAwareErrorHandler.php:L37-L39` — debug — API path JSON vs HTML content type — `path`, `content_type`
- [ ] `src/Handlers/NotFoundErrorHandler.php:L13-L32` — notice — **already logged** 404 — `method`, `path`, `ip`, `user_agent` (truncated)
- [ ] `config/middleware.php:L36-L51` — notice — confirm `logErrors=true`; 401/403 from Slim `HttpUnauthorizedException` / `HttpForbiddenException` use default handler (no dedicated structured deny) — `exception_class`
- [ ] `src/Utility/JsonResponseBody.php:L26-L28` — notice — optional single choke-point for JSON errors if callers pass logger — `error`, `http_status`
- [ ] `src/Utility/Security/HttpBasicCredentialsParser.php:L31-L32` — debug — not Basic — `reason` (`no_basic`)
- [ ] `src/Utility/Security/HttpBasicCredentialsParser.php:L40-L42` — info — malformed Basic payload — `reason` (`b64`|`no_colon`)
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L27-L29` — info — **already logged** missing header — (prod-invisible)
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L39-L42` — warning — **already logged** bad secret — `client_id`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L45-L47` — warning — unknown client after validate true (race) — `client_id`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L49-L50` — warning — public client hitting confidential API — `client_id`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L53-L59` — warning — missing IAM namespace — `client_id`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L62-L64` — debug — **already logged** accept — `client_id`
- [ ] `src/Utility/Security/AllowListedConfidentialClientAuthenticator.php:L33-L60` — (mixed) — **already logged** all deny/accept; **upgrade** missing-header `info` → `notice` for prod — `client_id`

**M6 summary: 10** core + 6 authenticator normalize items (**16** if all checked)

---

## M7 — Controllers / resources / connect / management / misc HTTP

**Count: 38**

### High-traffic resource controllers (401 without log)

- [ ] `src/Controllers/Resource/ResourcesController.php:L111-L113` — info — `getJwt` 401 after middleware (resolver empty) — `path`, `reason` (`user_unresolved`)
- [ ] `src/Controllers/Resource/ResourcesController.php:L175-L177` — info — `userinfo` 401 same — `path`
- [ ] `src/Controllers/Resource/ResourcesController.php:L213-L215` — info — `authorizations` 401 — `path`
- [ ] `src/Controllers/Resource/ResourcesController.php:L276-L277` — info — link ORK unauthenticated redirect — `path`
- [ ] `src/Controllers/Resource/ResourcesController.php:L281-L283` — warning — **already logged** ORK auth fail — `username` → hash
- [ ] `src/Controllers/Resource/ResourcesController.php:L291-L292` — warning — player fetch fail **unlogged** (unlike refresh) — `user_uuid`, `mundane_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L312-L318` — info — refresh unauth / no profile — `reason` (`login`|`no_profile`)
- [ ] `src/Controllers/Resource/ResourcesController.php:L324-L346` — info/warning — **already logged** refresh path
- [ ] `src/Controllers/Resource/ResourcesController.php:L416-L418` — notice — `linkOrkProfile` 400 **unlogged** — `idp_user_id_present`, `mundane_id_present`
- [ ] `src/Controllers/Resource/ResourcesController.php:L421-L425` — info — **already logged** 404 — `idp_user_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L431-L439` — warning — **already logged** 409 — `idp_user_id`, `requested_mundane_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L441` — error — non-conflict `RuntimeException` rethrown (Slim handler) — `idp_user_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L444` — info — **already logged** 204 — `idp_user_id`, `mundane_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L451-L460` — notice — revoke unauth / invalid `client_id` — `reason`, `client_db_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L464` — info — revoke success — `user_uuid`, `client_db_id`

### Local email/password + logout

- [ ] `src/Controllers/Client/AuthController.php:L94-L109` — notice — failed password / missing user (`orElse` null) — `email_sha256`, `reason` (`invalid_credentials`) — **never password**
- [ ] `src/Controllers/Client/AuthController.php:L136-L165` — notice — register validation helpers (duplicate of RegistrationService if unified) — `reason`
- [ ] `src/Controllers/Client/AuthController.php:L194-L196` — notice — register validation HTML return — `reason`
- [ ] `src/Controllers/Client/AuthController.php:L215-L218` — info — logout cache invalidate — `user_uuid`
- [ ] `src/Controllers/Client/AuthController.php:L227-L229` — info — RP-initiated logout redirect accepted — `host_match` (bool)
- [ ] `src/Controllers/Client/AuthController.php:L247-L269` — info — post-logout URI rejected (falls through to home) — `reason` (`no_base`|`parse`|`scheme`|`userinfo`|`host`|`port`)
- [ ] `src/Controllers/Client/BaseAuthController.php:L36-L70` — info — **already logged** session/JWT/redirect — `user_id`, `redirect_policy`

### Connect handoff (many errors logged; several 400s not)

- [ ] `src/Controllers/Client/ConnectController.php:L46-L47` — notice — missing `link_token` GET — `reason` (`missing_token`)
- [ ] `src/Controllers/Client/ConnectController.php:L52-L54` — notice — peek fail GET — `reason` (`peek_invalid`)
- [ ] `src/Controllers/Client/ConnectController.php:L81-L83` — notice — login peek fail — `reason` (`peek_invalid`)
- [ ] `src/Controllers/Client/ConnectController.php:L93-L94` — notice — bad password (token **not** consumed) — `email_sha256`, `reason` (`invalid_credentials`)
- [ ] `src/Controllers/Client/ConnectController.php:L103-L109` — error — **already logged** consumeJti throw
- [ ] `src/Controllers/Client/ConnectController.php:L111-L112` — warning — consume returned false (replay) **unlogged** — `jti`, `mundane_id`
- [ ] `src/Controllers/Client/ConnectController.php:L117-L136` — warning/error — **already logged** link write
- [ ] `src/Controllers/Client/ConnectController.php:L143-L144` — warning — **already logged** session regenerate
- [ ] `src/Controllers/Client/ConnectController.php:L162-L184` — notice — register peek / password mismatch / `RegistrationService` fail — `reason`, `email_sha256`
- [ ] `src/Controllers/Client/ConnectController.php:L196-L204` — error/warning — consume throw **logged**; replay false **unlogged** — `jti`
- [ ] `src/Controllers/Client/ConnectController.php:L215-L237` — error — **already logged** orphan account
- [ ] `src/Controllers/Client/ConnectController.php:L263-L267` — warning — completion JWT skipped (secret short) silent fallback home — `reason` (`completion_secret_short`)
- [ ] `src/Controllers/Client/ConnectController.php:L297-L306` — error — `ORK_BASE_URL` invalid (uncaught → 500) — `reason` (not raw URL if sensitive)
- [ ] `src/Controllers/Client/ConnectController.php:L311-L319` — notice — `renderError` 400 (centralize) — `http_status`, `error_kind` (not full user message if noisy)

### Management / API / docs

- [ ] `src/Controllers/Management/ManagementController.php:L52` — info — **already logged** cleanup start
- [ ] `src/Controllers/Management/ManagementController.php:L67-L70` — error — **already logged** cleanup fail (prefer context array not string concat) — `exception_message`
- [ ] `src/Controllers/Management/ManagementController.php:L102-L124` — notice — client created — `client_id`, `is_confidential` — **never client_secret**
- [ ] `src/Controllers/Management/ManagementController.php:L127-L145` — notice — client updated or id miss (still 302) — `client_db_id`, `found` (bool)
- [ ] `src/Controllers/Api/ApiController.php:L16-L28` — debug — policy check (no validation/log) — `is_authorized` (bool) — **do not log policy JSON**
- [ ] `src/Controllers/SwaggerController.php:L45-L47` — notice — docs 404 — `path`
- [ ] `src/Utility/Security/RedirectValidator.php:L13-L35` — debug — unsafe redirect rejected — `reason` (`empty`|`protocol_relative`|`bad_url`|`host`)
- [ ] `src/Utility/Security/ScriptAlertResponse.php:L18-L36` — info — provider error / state (if not logged upstream) — `provider`, `oauth_error`

**M7 summary: 38**

---

## Milestone counts

| Milestone | Checklist items | Notes |
|-----------|-----------------|--------|
| Global correlation / logger | 3 | Prerequisite for all HTTP rows |
| M1 HTTP middleware | 28 | Plus unused logger on `LocalIdpAuthMiddleware` |
| M2 OAuth / social | 36 | Token + approve are the biggest holes |
| M3 PVH / JWT / worker | 32 | Many exist; **upgrade levels** for prod `NOTICE` |
| M4 IAM client API | 27 | `jsonError` is the choke point |
| M5 Persistence / ORK / registration | 31 | Empty-policy already `error` |
| M6 Error handlers / authenticators | 16 | Slim 401/403 unstructured |
| M7 Controllers / connect / mgmt | 38 | Resource 401s + connect 400s |
| **Total** | **211** | Includes “already logged — upgrade/normalize” rows |

---

## Files with **zero** logging today and **high traffic** (implement first)

Priority is “requests per second × security relevance × current silence.”

| Priority | File | Why |
|----------|------|-----|
| P0 | `src/Controllers/Server/OAuth/OAuthTokenAction.php` | Every token grant/deny; League errors unlogged |
| P0 | `src/Middleware/LocalIdpAuthMiddleware.php` | Logger injected, **never used**; Bearer deny + 302 login |
| P0 | `src/Utility/Security/OAuthAccessTokenFallback.php` | Shared 401 for userinfo + client-restricted |
| P0 | `src/Controllers/Resource/ClientResourcesController.php` (`jsonError`) | All IAM 4xx/409 |
| P0 | `src/Controllers/Resource/ResourcesController.php` (`getJwt`/`userinfo` 401) | Integrator heartbeat after auth middleware |
| P1 | `src/Middleware/ManagementMiddleware.php` | No logger; 403/500 on key |
| P1 | `src/Middleware/LocalAdminUserMiddleware.php` | Silent 302 for non-admin |
| P1 | `src/Middleware/JsonBodyParserMiddleware.php` | Bad JSON silently ignored |
| P1 | `src/Middleware/OAuthAccessTokenElevationMiddleware.php` | Partial (only League catch) |
| P1 | `src/Controllers/Server/OAuth/OAuthApproveAction.php` | Consent allow/deny/errors |
| P1 | `src/Utility/Security/OAuthCallbackValidator.php` | Social CSRF / provider error |
| P1 | `src/Controllers/Client/AuthController.php` | Failed login (credential stuffing signal) |
| P1 | `src/Services/RegistrationService.php` | Shared register failures |
| P1 | `src/Controllers/Client/ConnectController.php` | Unlogged peek/credential/replay 400s |
| P2 | `src/Middleware/SessionMiddleware.php` + `SessionStorage.php` | Redis session fallback |
| P2 | `src/Utility/Security/CurrentUserResolver.php` | Empty session after “auth” |
| P2 | `src/Controllers/Server/OAuth2ServerController.php` | `authorizePost` noop |
| P2 | `src/Controllers/Api/ApiController.php` | Policy oracle, no logs |
| P2 | `src/Persistence/Server/Repositories/ClientRepository.php` | Unknown client / bad secret |
| P2 | `src/Utility/PvhCacheRecord.php` | Corrupt Redis JSON → treat as miss |
| P2 | `src/Handlers/ApiAwareErrorHandler.php` | Relies on Slim default text logs |

**Has logger but high-traffic deny still silent:** `LocalIdpAuthMiddleware`, `OAuthAccessTokenElevationMiddleware` (most branches), `ClientResourcesController` error paths, `ConnectController` peek/credential/replay.

---

## Gaps vs existing `->info` / `->debug` / `->notice` (normalize)

### 1. Production min-level vs actual levels

`config/container.php:L78`: prod = **NOTICE**. These **do not appear in production** unless `APP_DEBUG=true`:

| Pattern | Examples |
|---------|----------|
| `debug` | `Jwt::validateJwtSignature` fail; `LowLatencyController::rejectValidate`; `CachedJwt` / `ClientRestricted` PVH debug; `PvhAuthorizationGate` non-miss; `ConfidentialClient*`: credentials accepted; `JwtPvhRefreshService` entry |
| `info` | Confidential missing Basic header; JWT elevation invalid token; `BaseAuthController` login success; `OAuthSocialCallbackHandler` start; most `OrkService` request/success; `OrkLinkToken` expired/decode; `linkOrkProfile` 404/success; client IAM claim add/delete; `ManagementController` cleanup start; `User authenticated` |

**Spike decision:** either lower prod min-level to `INFO`, or promote security denials to `notice` (recommended for auth 401/403/409).

### 2. Inconsistent deny levels

| Outcome | Current | Suggested |
|---------|---------|-----------|
| CSRF fail | `warning` | keep `warning` |
| Confidential bad secret / not allow-listed | `warning` | keep |
| Confidential missing header | `info` | `notice` (prod) |
| JWT elevation bad access token | `info` | `notice` |
| Cached JWT / client-restricted 401 | *(none)* or `debug` | `notice` |
| `validate` reject | `debug` | `notice` |
| `validate` stale/unknown/current | `notice` | keep |
| OAuth authorize League error | `error` | `notice` if protocol, `error` if 500 |
| OAuth token League error | *(none)* | `notice` |
| OAuth flow HTML protocol error | *(none)* | `notice` |
| Policy load empty fallback | `error` | keep |
| Malformed policy claim skip | `error` | `warning` (data quality, request continues) |
| OrkLinkToken expired | `info` | `notice` or keep `info` after level change |
| OrkLinkToken bad signature | `warning` | keep |
| OrkLinkToken decode | `info` | `notice` (malformed) |
| Connect consume/link failures | `error`/`warning` | keep |
| Management key 403 | *(none)* | `warning` |
| Failed password login | *(none)* | `notice` (rate/security) |

### 3. Message / context style drift

- **Prefix styles:** `ConfidentialClientAuth: …` vs `ConfidentialClientBasic: …` vs `jwt validate …` vs `oauth social …` vs `ORK GetPlayer …` vs `LinkORK:` / `RefreshORK:` vs `ConnectController login:`.
- **Normalize to:** `{domain} {action} {outcome}` snake-ish English, e.g. `oauth token denied`, `client iam json_error`, `auth login failed`.
- **Context:** `LowLatencyController` / `PvhAuthorizationGate` already use `user_uuid`, `aud`, `client_id` (duplicate of `aud`). Reuse that trio everywhere JWT-related.
- **Exceptions:** `ManagementController` concatenates message into the log string (`L68`); others use `['msg'|`detail`|`exception`]`. Prefer `exception_class` + `exception_message`.
- **Traces:** `OAuthFlowErrorRenderer` logs `getTraceAsString()` — too large / sensitive for default prod.
- **PII / secrets already too chatty:** `OrkService::authorize` `on_stats` logs effective URI (`L55`); GetParkShortInfo logs `rawBody` (`L159`); LinkORK logs `username`; Connect logs `email` on errors (`L105`, `L125`). Hash or drop.

### 4. Duplicate vs missing layers

- **PVH:** gate `debug`/`info` **plus** middleware `debug` **plus** `LowLatencyController` `notice` — three layers; prod only sees the controller notices.
- **Confidential clients:** authenticators log; middleware wrappers do not (OK).
- **IAM 4xx:** validators throw; controller `jsonError` silent — log **once** in `jsonError`.
- **Social:** handler logs start/error; `orElseGet` user-create and state/provider-error are silent.

### 5. Logger injected but unused

- `src/Middleware/LocalIdpAuthMiddleware.php` — `$this->logger` assigned, zero calls.
- Social controllers hold logger only to pass into `OAuthSocialCallbackHandler`.
- `AuthController` inherits logger; failed login never logs.

### 6. Optional blank / `orElseGet` without logs (index)

| Location | Behavior |
|----------|----------|
| `CachedJwtLocalIdpAuthMiddleware.php:L39-L47` | `orElseThrow` 401 |
| `ClientRestrictedAuthMiddleware.php:L44-L47` | `orElseThrow` 401 |
| `ConfidentialClientAuthenticator.php:L45-L56` | `orElseThrow` 401 |
| `JwtPvhRefreshService.php:L94` | `orElseGet` user missing (**logged**) |
| `ClientResourcesController.php:L237-L239` | metadata 404 |
| `ClientResourcesController.php:L371-L376` | user 400/404 |
| `ClientResourcesController.php:L417-L422` | login 400/404 |
| `AuthController.php:L94-L96` | user/login null |
| `ConnectController.php:L81-L92` | peek / login null |
| `AuthorizationJwtAssembler.php:L155-L185` | session/login fallbacks |
| `CurrentUserResolver.php:L30-L32` | `orElse(null)` |
| `UserLoginRepository.php:L48` | `orElse(false)` |
| `ClientRepository.php:L106` | `orElse(false)` validate |
| `PvhQueueMessage.php:L44-L54` | `Optional::blank` |
| `HttpBasicCredentialsParser.php:L23-L42` | `Optional::blank` |
| Social `orElseGet` user/login create | new account, no log |

---

## Suggested implementation order

1. **Correlation + prod level policy** (global + M1 Session/JSON).
2. **P0 middleware/token/IAM `jsonError`** (M1 leftover denials, M2 token, M4, M6 authenticators upgrade).
3. **Resource 401 + connect 400 + login fail** (M7).
4. **Level upgrades** on existing PVH/JWT `debug`/`info` (M3) so prod matches `LowLatencyController` notices.
5. **Normalize ORK/PII** (M5) and OAuth approve/authorize protocol vs 500 split (M2).
6. **Low-volume** management, docs 404, Redis fallback, worker idle debug.

---

## Out of scope / do not log

- Raw JWTs, passwords, client secrets, CSRF tokens, session cookie values.
- Full ORK authorize URLs, `rawBody`, full IAM `policy` JSON, metadata blobs.
- Per-request CORS OPTIONS at `info`/`notice` (debug + sample only).
- Tests under `tests/**` (except if adding assertions for new logs).
)
