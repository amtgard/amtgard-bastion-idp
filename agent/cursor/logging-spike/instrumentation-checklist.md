# Logging spike — instrumentation checklist

**Baseline commit:** `9980cf5`

**Repository:** `amtgard-idp`  
**Companion:** [design-plan.md](./design-plan.md)  
**Scope:** Design-only catalog. Do not treat an existing `debug`/`notice` line as done when the design maps the HTTP outcome to `error`/`critical`/`alert`.

**Format:** `- [ ] path:Lstart-Lend — level — rationale — context keys (no secrets)`

**Channels (from design):** `app` default; `security` for 401/403; `oauth` for AS/token/social; `pvh` for gate/worker/cache; `audit` for IAM mutations and management key.

**Redaction:** never log `Authorization`, JWTs, passwords, `MANAGEMENT_KEY` / `query.key`, CSRF tokens, session ids, client secrets, PEM contents, raw `email` (use `email_sha256`), policy ORN lists (use `policy_hash` / claim count), metadata blobs.

Milestone mapping to [design-plan.md](./design-plan.md) § Phased milestones:

| Checklist | Design phase | Focus |
|-----------|--------------|--------|
| M1 | Phase 0 — Spike | Platform wiring + `/resources/validate` + `/oauth/token` |
| M2 | Phase 1 — Pilot | Authn/authz middleware deny paths |
| M3 | Phase 1 — Pilot | JWT / PVH gate / `orElseThrow` |
| M4 | Phase 1 — Pilot | Worker + queue correlation |
| M5 | Phase 2 — Rollout | OAuth authorization server |
| M6 | Phase 2 — Rollout | Login, register, social, connect |
| M7 | Phase 2 — Rollout | Resource / IAM APIs, `jsonError`, 4xx/409 |
| M8 | Phase 2 — Rollout | Repositories and silent fallbacks |
| M9 | Phase 3 — Hardening | Management, ORK, session/Redis infra |
| M10 | Phase 1–2 tooling | Log-read API / ORNs (new files) |

---

## Summary counts

| Milestone | Items | Channel bias |
|-----------|------:|--------------|
| M1 Spike platform + slice | 24 | `app` / `oauth` / `pvh` |
| M2 Auth middleware | 26 | `security` / `audit` |
| M3 JWT / PVH / Optional | 29 | `security` / `pvh` |
| M4 Workers + queue | 17 | `pvh` |
| M5 OAuth AS | 22 | `oauth` |
| M6 Client identity | 50 | `oauth` / `security` / `app` |
| M7 Resource + IAM APIs | 38 | `app` / `audit` / `pvh` |
| M8 Repositories + fallbacks | 51 | `app` / `audit` / `pvh` |
| M9 Management / ORK / infra | 28 | `audit` / `app` |
| M10 Log-read API (new) | 10 | `audit` / `app` |
| **Total** | **295** | |

---

## High-traffic files with zero logging today

These sit on hot HTTP or persistence paths and contain **no** `LoggerInterface` calls (`src/` only; tests excluded). Logger injection without a call still counts as zero.

| File | Why it is high-traffic |
|------|------------------------|
| `src/Middleware/LocalIdpAuthMiddleware.php` | Session + OAuth fallback for HTML resource routes |
| `src/Middleware/ManagementMiddleware.php` | Every `/management/*` request; key compare is silent |
| `src/Middleware/LocalAdminUserMiddleware.php` | Admin UI gate; deny is a silent 302 |
| `src/Middleware/JsonBodyParserMiddleware.php` | Global JSON parse; invalid JSON is swallowed |
| `src/Middleware/SessionMiddleware.php` | Every request; Redis→file fallback is silent |
| `src/Middleware/CorsMiddleware.php` | Global; OPTIONS short-circuit |
| `src/Middleware/ConfidentialClientAuthMiddleware.php` | Client IAM API gate (delegates; no request-scoped line) |
| `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` | `POST /resources/link-ork-profile` |
| `src/Middleware/ConfidentialClientCredentialMiddleware.php` | Confidential-only routes |
| `src/Utility/PvhGate.php` | Central 401/409 JSON writer for validate + middleware |
| `src/Utility/Security/OAuthAccessTokenFallback.php` | Bearer elevation when JWT is an access token |
| `src/Utility/JsonResponseBody.php` | Shared 4xx JSON writer (`writeError`) |
| `src/Handlers/ApiAwareErrorHandler.php` | Slim default handler; no IDP-level HTTP map |
| `src/Controllers/Client/AuthController.php` | Email login/register/logout (logger unused on failures) |
| `src/Controllers/Client/GoogleAuthController.php` | Google start + user/login `orElseGet` |
| `src/Controllers/Client/DiscordAuthController.php` | Discord start + email deny throw |
| `src/Controllers/Client/FacebookAuthController.php` | Facebook start + user/login `orElseGet` |
| `src/Controllers/Client/AppleAuthController.php` | Apple start + missing-email throw |
| `src/Controllers/Server/OAuth/OAuthTokenAction.php` | `POST /oauth/token` (4xx via League, no IDP log) |
| `src/Controllers/Server/OAuth/OAuthApproveAction.php` | Approve/deny; League catch has no local log |
| `src/Controllers/Server/OAuth2ServerController.php` | Facade + silent session clears |
| `src/Services/RegistrationService.php` | Shared register validation failures |
| `src/Services/ClientIamPolicyService.php` | Claim add/delete/list |
| `src/Services/ClientIamMetadataService.php` | Metadata upsert/get/delete |
| `src/Services/ResourcesUserinfoService.php` | `GET /resources/userinfo` payload |
| `src/Utility/Security/CurrentUserResolver.php` | Session user resolve for jwt/userinfo/profile |
| `src/Utility/Client/ClientResourcesRequestResolver.php` | Client IAM id lookups |
| `src/Utility/Security/OAuthCallbackValidator.php` | Social state / provider-error |
| `src/Persistence/Client/Repositories/UserOrkProfileRepository.php` | ORK link 409/idempotent |
| `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php` | Claim cap / `orElseThrow` |
| `src/Persistence/Common/Repositories/ClaimOrnValidator.php` | ORN validation throws |
| `src/Persistence/Server/Repositories/ClientRepository.php` | OAuth client lookup / secret check |
| `src/Persistence/Client/Repositories/UserLoginRepository.php` | Login resolve `orElse(false)` |
| `src/Persistence/Server/Repositories/UserLoginClientRepository.php` | JWT metadata miss |
| `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php` | OAuth approve persist/revoke |
| `src/Persistence/Server/Repositories/AccessTokenRepository.php` | Token persist/revoke |
| `src/Persistence/Server/Repositories/RefreshTokenRepository.php` | Refresh persist/revoke |
| `src/Persistence/Server/Repositories/AuthCodeRepository.php` | Auth code persist/revoke |
| `src/Persistence/Server/Repositories/ScopeRepository.php` | Scope resolve |
| `src/Utility/PvhCacheRecord.php` | Redis JSON parse miss |
| `src/Utility/Pvh/PvhQueueMessage.php` | Worker message parse miss |
| `src/Utility/Redis/PubSubRedisConfig.php` | Redis connect fallback |
| `src/Utility/Security/SessionStorage.php` | Session Redis unreachable |
| `src/Utility/OAuthKeyMaterial.php` | Missing/unreadable signing keys |
| `src/Models/AmtgardIdpJwt.php` | JWT mint + Redis seed |
| `src/Controllers/Api/ApiController.php` | Policy check API |
| `src/Controllers/SwaggerController.php` | Docs 404 |
| `src/Controllers/HomeController.php` | `/` |
| `src/Controllers/VersionController.php` | `/version` |

Files that **already log** but still have silent 4xx / Optional / catch gaps are listed under milestones (not in this table).

---

## M1 — Phase 0 spike: platform + validate + token

Wire correlation/redaction here; instrument the two spike routes so a `request_id` reconstructs `/resources/validate` and `/oauth/token`.

**New types (create; no current lines):** `CorrelationMiddleware`, `HttpObservabilityMiddleware`, `CorrelationContext`, `CorrelationProcessor`, `RedactionProcessor`, `SqliteLogHandler`, `LogChannelFactory` — see design-plan Appendix A.

- [ ] `config/container.php:L72-L86` — emergency — logger factory: channels, processors, `LOG_ROOT`, fail-open handlers — `log_root`, `trace_level` (no key material)
- [ ] `config/middleware.php:L13-L30` — info — add correlation + HTTP observability after session, before CORS — `route`, `method` (no query)
- [ ] `config/middleware.php:L32-L53` — critical — Slim error middleware: keep logging on; attach `request_id` via `ApiAwareErrorHandler` — `request_id`, `http_status`, `exception_class`
- [ ] `src/Handlers/ApiAwareErrorHandler.php:L16-L26` — critical — map uncaught → 5xx; 4xx Slim HTTP exceptions → error; include `request_id` — `path`, `http_status`, `exception_class`
- [ ] `src/Handlers/NotFoundErrorHandler.php:L13-L32` — notice — keep 404 notice; **redact** `query` (`key`, `code`, tokens) — `method`, `path`, `ip`, `user_agent` (no raw query)
- [ ] `src/Middleware/JsonBodyParserMiddleware.php:L20-L24` — error — invalid JSON still forwarded as empty body → later 4xx — `content_type`, `json_error` (no body)
- [ ] `src/Middleware/SessionMiddleware.php:L17-L23` — warning — `session_start` / Redis handler path — `session_handler` (`files`\|`redis`)
- [ ] `src/Middleware/CorsMiddleware.php:L16-L18` — debug — OPTIONS short-circuit (no app handler) — `method`, `path`
- [ ] `src/Utility/JsonResponseBody.php:L26-L28` — error — central 4xx/409 writer if call sites omit a log — `error_code`, `http_status` (no message PII)
- [ ] `src/Utility/PvhGate.php:L74-L77` — error — 409 `stale_token` — `error_code=stale_token`, `http_status=409`
- [ ] `src/Utility/PvhGate.php:L79-L82` — error — 401 `unauthorized` — `error_code=unauthorized`, `http_status=401`
- [ ] `src/Utility/PvhGate.php:L84-L87` — error — factory 409 used by middleware stale path — `error_code=stale_token`, `outcome=StaleToken`
- [ ] `src/Utility/PvhGate.php:L89-L92` — error — shared JSON error helper — `error_code`, `http_status`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L91-L92` — error — validate 401 missing Bearer (today debug via reject) — `reason=missing_bearer`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L95-L96` — error — validate 401 bad signature — `reason=invalid_signature`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L99-L101` — error — validate 401 unparseable payload — `reason=invalid_payload`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L106-L111` — error — validate 401 missing `sub`/`aud` — `reason=missing_sub_or_aud`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L114-L119` — error — validate 401 issuer mismatch — `reason=invalid_issuer`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L125-L130` — error — validate 401 missing pvh/policy — `reason=missing_pvh_context`, `user_uuid`, `aud`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L137-L176` — info — validate 2xx current/miss (today notice; add info summary + keep debug detail) — `user_uuid`, `aud`, `access`, `outcome` (no `pvh` raw if redaction bans it; use `pvh_prefix`)
- [ ] `src/Controllers/Resource/LowLatencyController.php:L178-L190` — error — validate 409 stale (today notice) — `user_uuid`, `aud`, `outcome=StaleToken` (no presented/current pvh hex)
- [ ] `src/Controllers/Resource/LowLatencyController.php:L193-L202` — error — validate 401 unknown pvh (today notice) — `user_uuid`, `aud`, `access=Unknown`
- [ ] `src/Controllers/Resource/LowLatencyController.php:L208-L212` — error — `rejectValidate` currently logs **debug** then returns 401 — `reason`, `user_uuid`, `aud`
- [ ] `src/Controllers/Server/OAuth/OAuthTokenAction.php:L27-L30` — error — League `OAuthServerException` → 4xx HTTP, **no IDP log** — `oauth_step=token`, `http_status`, `error` (hint only; no `code`/`client_secret`)

**M1 count: 24**

---

## M2 — Phase 1 pilot: authn/authz middleware

- [ ] `src/Middleware/ManagementMiddleware.php:L22-L27` — emergency — `MANAGEMENT_KEY` unset → 500 — `reason=management_key_missing` (never log key)
- [ ] `src/Middleware/ManagementMiddleware.php:L30-L33` — emergency — key too short → 500 — `reason=management_key_too_short`, `key_len`
- [ ] `src/Middleware/ManagementMiddleware.php:L36-L39` — alert — wrong `?key=` → 403 — `reason=management_key_mismatch`, `path` (no query)
- [ ] `src/Middleware/ManagementMiddleware.php:L42-L42` — info — management key accepted — `path`, `audit_action=management_access`
- [ ] `src/Middleware/LocalAdminUserMiddleware.php:L34-L38` — debug — admin policy allow — `user_uuid`, `admin=true`
- [ ] `src/Middleware/LocalAdminUserMiddleware.php:L34-L39` — error — user present but not admin — `user_uuid`, `failure_reason=not_admin`
- [ ] `src/Middleware/LocalAdminUserMiddleware.php:L42-L49` — error — no session user → 302 profile — `failure_reason=unauthenticated`, `http_status=302`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L42-L44` — error — Bearer on session-only path — `failure_reason=bearer_not_allowed`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L49-L50` — error — session `client_id` not allow-listed — `client_id`, `failure_reason=client_not_authorized`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L54-L56` — debug — session user proceed — `user_uuid`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L59-L63` — debug — OAuth resource-server success — `oauth_user_id`, `oauth_client_id`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L64-L66` — warning — `OAuthServerException` swallowed — `exception_class`, `oauth_hint`
- [ ] `src/Middleware/LocalIdpAuthMiddleware.php:L77-L79` — notice — redirect to login (neither session nor token) — `path`, `http_status=302`
- [ ] `src/Middleware/CsrfMiddleware.php:L44-L49` — warning — already logs; keep + add `route` — `path`, `method`, `failure_reason=csrf`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L36-L42` — error — authorization JWT presented on `/resources/jwt` — `failure_reason=authorization_jwt_on_elevation`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L48-L51` — error — session client not allow-listed — `client_id`, `failure_reason=client_not_authorized`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L54-L56` — debug — session elevation proceed — `user_uuid`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L58-L60` — error — no Bearer and no session — `failure_reason=missing_access_token`
- [ ] `src/Middleware/OAuthAccessTokenElevationMiddleware.php:L63-L67` — error — invalid access token (today **info**) — `failure_reason=invalid_access_token`, `exception_class` (no token)
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L27-L29` — error — missing Basic (today info) — `failure_reason=missing_basic`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L34-L42` — error — bad secret (today warning) — `client_id`, `failure_reason=invalid_credentials`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L45-L47` — error — `orElseThrow` unknown client after validate — `client_id`, `failure_reason=unknown_client`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L49-L50` — error — public client on confidential route — `client_id`, `failure_reason=not_confidential`
- [ ] `src/Utility/Security/ConfidentialClientAuthenticator.php:L53-L59` — error — `orElseThrow` missing IAM namespace — `client_id`, `failure_reason=missing_iam_service`
- [ ] `src/Middleware/ConfidentialClientAuthMiddleware.php:L28-L30` — debug — IAM-scoped client accepted — `client_id`, `iam_service`
- [ ] `src/Middleware/ConfidentialClientCredentialMiddleware.php:L27-L29` — debug — credentials-only client accepted — `client_id`

Allow-listed Basic already logs missing/deny/accept (`AllowListedConfidentialClientAuthenticator.php:L33-L60`); upgrade missing/deny to **error**/**alert** on 401 when implementing M2 (same lines).

**M2 count: 26** (plus 3 level upgrades on allow-listed authenticator at L33–L55)

---

## M3 — Phase 1 pilot: JWT, PVH gate, Optional throws

Design: log **before** `orElseThrow` / HttpUnauthorized; 401/403 → `security` **error**.

- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L39-L40` — error — missing/invalid Bearer JWT — `failure_reason=jwt_required`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L42-L43` — error — `parseJwt` empty — `failure_reason=unparseable_jwt`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L44-L45` — error — missing `sub` — `failure_reason=missing_sub`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L46-L47` — error — missing `aud` — `failure_reason=missing_aud`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L49-L54` — debug — non-authorization payload → access-token fallback — `aud`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L61-L69` — debug — already logs proceed; add identity update for correlation processor — `user_uuid`, `aud`, `access`, `outcome`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L73-L73` — error — `StaleToken` 409 (no log today) — `user_uuid`, `aud`, `outcome=StaleToken`
- [ ] `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php:L74-L74` — error — `Unauthorized` after gate — `user_uuid`, `aud`, `access=Unknown`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L40-L42` — debug — session client allow-list bypass — `client_id`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L44-L44` — error — JWT validate `orElseThrow` — `failure_reason=jwt_invalid`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L45-L45` — error — parse `orElseThrow` — `failure_reason=unparseable_jwt`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L46-L46` — error — missing `sub` — `failure_reason=missing_sub`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L47-L47` — error — missing `aud` — `failure_reason=missing_aud`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L49-L50` — error — `aud` not in restricted list — `client_id`, `failure_reason=client_not_restricted`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L53-L58` — debug — access-token fallback — `aud`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L77-L77` — error — stale 409 — `user_uuid`, `aud`, `outcome=StaleToken`
- [ ] `src/Middleware/ClientRestrictedAuthMiddleware.php:L78-L78` — error — gate unauthorized — `user_uuid`, `aud`
- [ ] `src/Utility/Security/OAuthAccessTokenFallback.php:L29-L32` — error — silent catch → 401 — `failure_reason=invalid_access_token`
- [ ] `src/Utility/Security/OAuthAccessTokenFallback.php:L35-L38` — debug — fallback succeed — `user_uuid`, `client_id`
- [ ] `src/Utility/Jwt.php:L33-L35` — debug — `validateJwt` empty parse — `reason=empty_payload`
- [ ] `src/Utility/Jwt.php:L37-L49` — debug — aud/iss/exp mismatch — `reason` enum
- [ ] `src/Utility/Jwt.php:L56-L67` — debug — policy mismatch — `reason=policy_mismatch` (no policy JSON)
- [ ] `src/Utility/Jwt.php:L73-L87` — debug — signature fail (exists); keep + `exception_class` — `reason` (no JWT)
- [ ] `src/Utility/Jwt.php:L97-L104` — debug — request has no Bearer — `reason=missing_bearer`
- [ ] `src/Utility/Jwt.php:L119-L122` — debug — `parseJwt` not 3 segments — `reason=malformed_compact`
- [ ] `src/Utility/Pvh/PvhAuthorizationGate.php:L42-L47` — error — `Previous`/`Unknown` outcomes (today debug-only in `logGateOutcome`) — `user_uuid`, `aud`, `access`, `outcome`
- [ ] `src/Utility/Pvh/PvhAuthorizationGate.php:L76-L89` — info — cache miss seed (already info) — `user_uuid`, `aud`, `access=Miss`
- [ ] `src/Utility/Pvh/PvhAuthorizationGate.php:L98-L99` — warning — logger null → gate silent — `reason=pvh_logger_unbound`
- [ ] `src/Utility/PvhGate.php:L31-L56` — debug — evaluate Current/Previous/Miss/Unknown — `access` (no pvh hex)

**M3 count: 29**

---

## M4 — Phase 1 pilot: workers + PVH queue

Carry `request_id` on `PvhQueueMessage` when enqueued from HTTP (design § Worker / queue jobs).

- [ ] `bin/jwt-pvh-worker.php:L43-L45` — notice — already; add `job_id`, `correlation_root=worker` — `queue`, `job_id`
- [ ] `bin/jwt-pvh-worker.php:L52-L58` — error — malformed drop (exists); add `parse_reason` — `key`, `queue`
- [ ] `bin/jwt-pvh-worker.php:L62-L71` — notice — dequeue (exists); add `request_id` from message — `key`, `user_uuid`, `aud`, `request_id`
- [ ] `bin/jwt-pvh-worker.php:L72-L77` — critical — job fail + republish (exists error); add `exception_class` — `key`, `user_uuid`, `aud`
- [ ] `src/Utility/Pvh/PvhQueueMessage.php:L35-L45` — error — schema miss → blank Optional — `reason=invalid_queue_payload`
- [ ] `src/Utility/Pvh/PvhQueueMessage.php:L53-L54` — error — silent `catch (\Throwable)` — `exception_class`
- [ ] `src/Utility/Pvh/PvhQueueMessage.php:L20-L25` — debug — encode/publish — `user_uuid`, `aud` (add `request_id` when field exists)
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L35-L42` — debug — PVH cache miss / corrupt JSON (`fromJson` null) — `user_uuid`, `aud`, `cache=miss`
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L45-L47` — debug — PVH cache write — `user_uuid`, `aud`
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L68-L78` — info — logout invalidate SCAN — `user_uuid`, `keys_deleted`
- [ ] `src/Persistence/Server/Repositories/RedisCacheRepository.php:L81-L93` — notice — enqueue (exists); add originating `request_id` — `user_uuid`, `aud`, `queue`, `request_id`
- [ ] `src/Services/JwtPvhRefreshService.php:L40-L43` — debug — exists — `user_uuid`, `aud`
- [ ] `src/Services/JwtPvhRefreshService.php:L57-L64` — info — noop (today notice) — `user_uuid`, `aud`
- [ ] `src/Services/JwtPvhRefreshService.php:L85-L90` — info — rotated (today notice) — `user_uuid`, `aud` (no raw pvh)
- [ ] `src/Services/JwtPvhRefreshService.php:L94-L100` — warning — user missing `orElseGet` (exists) — `user_uuid`, `aud`
- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L76-L96` — critical — all hosts fail — `hosts`, `exception_class`
- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L137-L144` — warning — reachability probe silent catch — `host`, `fallback=true`

**M4 count: 17**

---

## M5 — Phase 2 rollout: OAuth authorization server

- [ ] `src/Controllers/Server/OAuth2ServerController.php:L24-L26` — debug — token dispatch — `oauth_step=token`
- [ ] `src/Controllers/Server/OAuth2ServerController.php:L28-L30` — debug — approve dispatch — `method`
- [ ] `src/Controllers/Server/OAuth2ServerController.php:L34-L38` — notice — clear session user id — `oauth_step=clear_authn`
- [ ] `src/Controllers/Server/OAuth2ServerController.php:L41-L45` — notice — clear authorization state — `oauth_step=clear_authz`
- [ ] `src/Controllers/Server/OAuth2ServerController.php:L58-L60` — notice — `authorizePost` empty 200 — `oauth_step=authorize_post_noop`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L52-L57` — debug — validate/store vs load auth request — `client_id`, `has_stored_request`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L63-L66` — warning — session user id not found — `session_user_id`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L80-L98` — error — League exception (exists); add `http_status`, `client_id` — `oauth_step`, `http_status` (no `code`)
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L99-L108` — critical — Throwable → 500 (renderer logs) — `oauth_step`, `exception_class`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L139-L145` — info — redirect to login — `http_status=301`, `client_id`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L148-L165` — debug — approval check / missing client entity — `client_id`, `approved`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L168-L188` — info — send to `/oauth/approve` — `client_id`, `scope_count`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L191-L201` — info — authorization completed — `client_id`, `user_uuid`
- [ ] `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php:L208-L211` — warning — PVH seed skipped (exists) — `reason=user_unresolved`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L43-L51` — error — League exception, no local log — `oauth_step=approve`, `http_status`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L52-L61` — critical — Throwable (renderer) — `oauth_step=approve`, `exception_class`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L71-L89` — info — user allowed + persist authz — `client_id`, `user_uuid`, `action=allow`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L82-L84` — warning — allow but missing client/session — `client_id`, `has_session`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L92-L96` — notice — user denied — `action=deny`
- [ ] `src/Controllers/Server/OAuth/OAuthApproveAction.php:L99-L114` — error — `getClientEntity` null → fatal on `getName()` — `client_id`
- [ ] `src/Controllers/Server/OAuth/OAuthFlowErrorRenderer.php:L31-L37` — error — protocol/internal (exists); 4xx protocol should stay error, 5xx critical — `oauth_step`, `http_status`, `is_protocol_error`
- [ ] `src/Controllers/Server/OAuth/OAuthFlowErrorRenderer.php:L52-L69` — critical — token 500 (exists) — `oauth_step` (no stack in prod)

**M5 count: 22**

---

## M6 — Phase 2 rollout: client identity (login / social / connect)

- [ ] `src/Controllers/Client/AuthController.php:L94-L109` — error — email/password fail (`orElse(null)` + verify) — `failure_reason=invalid_credentials`, `email_sha256`
- [ ] `src/Controllers/Client/AuthController.php:L136-L141` — error — register required fields — `rule=required_fields`
- [ ] `src/Controllers/Client/AuthController.php:L144-L148` — error — invalid email format — `rule=email_format`
- [ ] `src/Controllers/Client/AuthController.php:L152-L156` — error — password mismatch — `rule=password_mismatch`
- [ ] `src/Controllers/Client/AuthController.php:L160-L165` — error — email already registered — `rule=email_exists`, `email_sha256`
- [ ] `src/Controllers/Client/AuthController.php:L189-L197` — error — validation short-circuit — `rule`
- [ ] `src/Controllers/Client/AuthController.php:L199-L203` — info — local user created — `user_uuid`, `email_sha256`
- [ ] `src/Controllers/Client/AuthController.php:L215-L218` — info — logout cache invalidate — `user_uuid`
- [ ] `src/Controllers/Client/AuthController.php:L227-L228` — info — RP-initiated logout redirect — `host` only
- [ ] `src/Controllers/Client/AuthController.php:L247-L269` — warning — rejected `post_logout_redirect_uri` — `reason=untrusted_logout_uri`
- [ ] `src/Services/RegistrationService.php:L29-L30` — error — required fields — `rule=required_fields`
- [ ] `src/Services/RegistrationService.php:L32-L33` — error — email format — `rule=email_format`
- [ ] `src/Services/RegistrationService.php:L35-L36` — error — email exists — `rule=email_exists`, `email_sha256`
- [ ] `src/Services/RegistrationService.php:L38-L40` — info — register success — `user_uuid`
- [ ] `src/Controllers/Client/BaseAuthController.php:L36-L70` — info — already logs auth/JWT/redirect; add `client_id`, `login_id` — `user_uuid`, `redirect_policy` (no jwt)
- [ ] `src/Utility/Security/OAuthCallbackValidator.php:L13-L16` — error — provider `error` query — `provider`, `oauth_error` (no `error_description` if PII)
- [ ] `src/Utility/Security/OAuthCallbackValidator.php:L18-L19` — error — state mismatch — `provider`, `failure_reason=invalid_state`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L51-L56` — error — validator fail, **no log** — `provider`, `failure_reason=callback_validation`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L59-L74` — info/debug — exists — `provider`, `provider_user_id`, `email_sha256`
- [ ] `src/Utility/Security/OAuthSocialCallbackHandler.php:L75-L86` — error — exists — `provider`, `exception_class`
- [ ] `src/Controllers/Client/GoogleAuthController.php:L47-L61` — info — redirect to Google — `provider=Google`
- [ ] `src/Controllers/Client/GoogleAuthController.php:L87-L92` — info — `orElseGet` create user — `provider=Google`, `provision=new_user`, `email_sha256`
- [ ] `src/Controllers/Client/GoogleAuthController.php:L101-L103` — info — `orElseGet` create login — `provider=Google`, `provision=new_login`
- [ ] `src/Controllers/Client/DiscordAuthController.php:L47-L59` — info — redirect to Discord — `provider=Discord`
- [ ] `src/Controllers/Client/DiscordAuthController.php:L85-L88` — error — email permission denied throw — `provider=Discord`, `failure_reason=email_denied`
- [ ] `src/Controllers/Client/DiscordAuthController.php:L90-L95` — info — create user `orElseGet` — `provider=Discord`, `provision=new_user`
- [ ] `src/Controllers/Client/DiscordAuthController.php:L104-L106` — info — create login `orElseGet` — `provider=Discord`, `provision=new_login`
- [ ] `src/Controllers/Client/FacebookAuthController.php:L47-L56` — info — redirect to Facebook — `provider=Facebook`
- [ ] `src/Controllers/Client/FacebookAuthController.php:L85-L90` — info — create user `orElseGet` — `provider=Facebook`, `provision=new_user`
- [ ] `src/Controllers/Client/FacebookAuthController.php:L99-L101` — info — create login `orElseGet` — `provider=Facebook`, `provision=new_login`
- [ ] `src/Controllers/Client/AppleAuthController.php:L42-L54` — info — redirect to Apple — `provider=Apple`
- [ ] `src/Controllers/Client/AppleAuthController.php:L95-L98` — error — Apple email missing throw — `provider=Apple`, `failure_reason=email_missing`
- [ ] `src/Controllers/Client/AppleAuthController.php:L101-L110` — info — create user `orElseGet` — `provider=Apple`, `provision=new_user`
- [ ] `src/Controllers/Client/AppleAuthController.php:L120-L124` — info — create login `orElseGet` — `provider=Apple`, `provision=new_login`
- [ ] `src/Controllers/Client/ConnectController.php:L46-L48` — error — GET missing `link_token` → 400 — `reason=missing_link_token`
- [ ] `src/Controllers/Client/ConnectController.php:L52-L54` — error — peek fail → 400 — `reason=invalid_or_expired_token`
- [ ] `src/Controllers/Client/ConnectController.php:L81-L83` — error — login peek fail — `reason=invalid_or_expired_token`
- [ ] `src/Controllers/Client/ConnectController.php:L90-L94` — error — connect login bad password — `email_sha256`, `failure_reason=invalid_credentials`
- [ ] `src/Controllers/Client/ConnectController.php:L103-L109` — error — consumeJti throw (exists) — `mundane_id`, `email_sha256` (drop raw email)
- [ ] `src/Controllers/Client/ConnectController.php:L111-L112` — error — jti replay — `reason=jti_replay`
- [ ] `src/Controllers/Client/ConnectController.php:L117-L136` — error/warning — link conflict/fail (exists) — `idp_user_id`, `mundane_id`
- [ ] `src/Controllers/Client/ConnectController.php:L162-L164` — error — register peek fail — `reason=invalid_or_expired_token`
- [ ] `src/Controllers/Client/ConnectController.php:L167-L168` — error — password mismatch — `rule=password_mismatch`
- [ ] `src/Controllers/Client/ConnectController.php:L183-L184` — error — `RegistrationService` fail — `rule`
- [ ] `src/Controllers/Client/ConnectController.php:L203-L204` — error — register jti replay — `reason=jti_replay`
- [ ] `src/Controllers/Client/ConnectController.php:L263-L267` — warning — completion JWT skipped (secret short) — `reason=missing_shared_secret`, `ork_host`
- [ ] `src/Controllers/Client/ConnectController.php:L297-L307` — emergency — `ORK_BASE_URL` invalid throw — `reason=ork_base_url`
- [ ] `src/Controllers/Client/ConnectController.php:L311-L319` — error — HTML 400 `renderError` — `http_status=400`
- [ ] `src/Services/OrkLinkTokenService.php:L36-L37` — emergency — shared secret unset/short — `reason=ork_link_secret`
- [ ] `src/Services/OrkLinkTokenService.php:L54-L80` — warning/error — peek failures (exist; align 4xx-causing to error) — `reason` enum (no jwt, no email)

**M6 count: 50**

---

## M7 — Phase 2 rollout: resource APIs, jsonError, 4xx/409

- [ ] `src/Utility/Security/CurrentUserResolver.php:L23-L24` — debug — no session `user_id` — `reason=no_session_user`
- [ ] `src/Utility/Security/CurrentUserResolver.php:L30-L32` — warning — session id not found in DB — `session_user_id`, `reason=user_missing`
- [ ] `src/Controllers/Resource/ResourcesController.php:L111-L113` — error — `GET /resources/jwt` 401 — `route=resources.jwt`
- [ ] `src/Controllers/Resource/ResourcesController.php:L116-L119` — info — JWT remint 200 — `user_uuid`, `aud` (no jwt)
- [ ] `src/Controllers/Resource/ResourcesController.php:L175-L177` — error — `GET /resources/userinfo` 401 — `route=resources.userinfo`
- [ ] `src/Controllers/Resource/ResourcesController.php:L180-L181` — info — userinfo 200 — `user_uuid`, `has_ork_profile`
- [ ] `src/Controllers/Resource/ResourcesController.php:L214-L215` — error — authorizations 401 — `route=resources.authorizations`
- [ ] `src/Controllers/Resource/ResourcesController.php:L275-L277` — notice — link ORK unauthenticated 302 — `route=linkOrk`
- [ ] `src/Controllers/Resource/ResourcesController.php:L281-L283` — error — ORK auth fail (exists warning) — `username` → `email_sha256` or drop
- [ ] `src/Controllers/Resource/ResourcesController.php:L291-L292` — error — player fetch fail, **no log** — `mundane_id`, `user_uuid`
- [ ] `src/Controllers/Resource/ResourcesController.php:L312-L313` — notice — refresh ORK unauthenticated 302
- [ ] `src/Controllers/Resource/ResourcesController.php:L317-L318` — error — no ORK profile — `user_uuid`, `reason=no_profile`
- [ ] `src/Controllers/Resource/ResourcesController.php:L416-L418` — error — linkOrkProfile 400, **no log** — `reason=invalid_body`
- [ ] `src/Controllers/Resource/ResourcesController.php:L421-L425` — error — 404 unknown user (today info) — `idp_user_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L431-L439` — error — 409 conflict (exists warning) — `idp_user_id`, `mundane_id`
- [ ] `src/Controllers/Resource/ResourcesController.php:L441-L441` — critical — unexpected RuntimeException rethrow — `exception_class`
- [ ] `src/Controllers/Resource/ResourcesController.php:L452-L453` — notice — revoke unauthenticated 302
- [ ] `src/Controllers/Resource/ResourcesController.php:L459-L460` — error — invalid `client_id` — `reason=invalid_client`
- [ ] `src/Controllers/Resource/ResourcesController.php:L464-L470` — info — authorization revoked — `user_uuid`, `client_db_id`
- [ ] `src/Services/ResourcesUserinfoService.php:L22-L34` — debug — payload built — `user_uuid`, `has_ork_profile`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L73-L74` — error — add claim 400 `jsonError`, **no log** — `client_id`, `error_code=invalid_claim`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L77-L80` — info — exists — `client_id`, `idp_user_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L124-L125` — error — delete claim 400 — `client_id`, `error_code=invalid_claim`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L201-L202` — error — metadata upsert 400 — `client_id`, `login_id`, `error_code=invalid_metadata`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L237-L239` — error — metadata 404 `orElseGet` — `client_id`, `login_id`, `idp_user_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L321-L322` — error — service format 409 — `client_id`, `error_code=format_exists`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L371-L376` — error — `orElseGet` user 400/404 — `idp_user_id`, `http_status`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L417-L422` — error — `orElseGet` login 400/404 — `login_id`, `http_status`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L436-L438` — error — `jsonError` helper (if not logged at caller) — `http_status`, `error`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L448-L449` — error — service format 400 — `client_id`
- [ ] `src/Controllers/Resource/ClientResourcesController.php:L460-L469` — debug — validation throw before 400 — `field=service_format`, `rule`
- [ ] `src/Services/ClientIamPolicyService.php:L21-L35` — info — add claim — `client_id`, `user_uuid`, `resource` (no full ORN if long)
- [ ] `src/Services/ClientIamPolicyService.php:L38-L50` — info — delete claim — `client_id`, `user_uuid`
- [ ] `src/Services/ClientIamMetadataService.php:L23-L37` — info — metadata upsert — `client_id`, `login_id`, `encoding`, `payload_len`
- [ ] `src/Services/ClientIamMetadataService.php:L43-L47` — debug — metadata miss — `client_id`, `login_id`
- [ ] `src/Services/ClientIamMetadataService.php:L57-L59` — info — metadata delete — `client_id`, `login_id`
- [ ] `src/Controllers/Api/ApiController.php:L16-L28` — debug — `isAuthorized` result — `is_authorized` (no policy JSON / requirement string if sensitive)
- [ ] `src/Controllers/SwaggerController.php:L45-L47` — notice — docs 404 — `path`

**M7 count: 38**

---

## M8 — Phase 2 rollout: repositories, validators, silent fallbacks

- [ ] `src/Persistence/Common/Repositories/UserPolicy.php:L23-L30` — error — empty policy fallback (exists); add `user_id` — `user_id`, `fallback=empty_policy`
- [ ] `src/Persistence/Common/Repositories/UserPolicy.php:L36-L43` — error — encode fallback (exists) — `user_id`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimReader.php:L69-L75` — error — empty policy (exists) — `user_id`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimReader.php:L144-L151` — error — skip malformed claim (exists) — `user_id`, `orn`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L31-L32` — debug — idempotent add no-op — `user_id`, `service`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L65-L66` — error — `orElseThrow` missing `client_id` — `service`, `rule=client_required`
- [ ] `src/Persistence/Common/Repositories/UserPolicyClaimWriter.php:L113-L119` — error — claim cap — `user_id`, `client_id`, `claim_count`
- [ ] `src/Persistence/Common/Repositories/ClaimOrnValidator.php:L16-L21` — debug — required/length — `field`, `rule`
- [ ] `src/Persistence/Common/Repositories/ClaimOrnValidator.php:L28-L31` — error — parse catch rethrow — `field=orn`, `exception_class`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L26-L29` — debug — date parse fail → null — `field=ork_date`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L53-L63` — debug — park/kingdom `orElse(null)` — `user_id`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L93-L94` — debug — idempotent same mundane — `user_id`, `mundane_id`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L96-L98` — error — conflict throw — `user_id`, `existing_mundane_id`, `requested_mundane_id`
- [ ] `src/Persistence/Client/Repositories/UserOrkProfileRepository.php:L124-L144` — error — PDO 23000 → conflict/idempotent — `user_id`, `mundane_id`, `sqlstate`
- [ ] `src/Persistence/Server/Repositories/ClientRepository.php:L76-L77` — debug — unknown OAuth client — `client_id`
- [ ] `src/Persistence/Server/Repositories/ClientRepository.php:L91-L91` — debug — `orElse(null)` client entity — `client_id`
- [ ] `src/Persistence/Server/Repositories/ClientRepository.php:L100-L106` — warning — `validateClient` `orElse(false)` — `client_id`, `grant_type` (no secret)
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L36-L36` — debug — no local login — `user_id`
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L46-L48` — debug — `loginBelongsToUser` `orElse(false)` — `login_id`, `user_id`
- [ ] `src/Persistence/Client/Repositories/UserLoginRepository.php:L62-L63` — debug — no default login id — `user_id`
- [ ] `src/Persistence/Server/Repositories/UserLoginClientRepository.php:L33-L41` — debug — JWT metadata `orElse(null)` — `login_id`, `client_id`
- [ ] `src/Persistence/Server/Repositories/UserLoginClientRepository.php:L49-L51` — debug — getMetadata miss — `login_id`, `client_id`
- [ ] `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php:L36-L37` — debug — authorize idempotent — `user_identifier`, `client_id`
- [ ] `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php:L40-L46` — info — new client authorization — `user_identifier`, `client_id`
- [ ] `src/Persistence/Server/Repositories/UserClientAuthorizationRepository.php:L49-L55` — info — revoke — `user_identifier`, `client_id`
- [ ] `src/Persistence/Server/Repositories/AccessTokenRepository.php:L58-L68` — info — persist access token — `client_id`, `user_identifier`, `exp` (no token id if treated as secret; use `token_id_prefix`)
- [ ] `src/Persistence/Server/Repositories/AccessTokenRepository.php:L71-L76` — info — revoke — `token_id_prefix`
- [ ] `src/Persistence/Server/Repositories/RefreshTokenRepository.php:L51-L66` — info — persist refresh — `token_id_prefix`
- [ ] `src/Persistence/Server/Repositories/RefreshTokenRepository.php:L68-L80` — info — revoke refresh — `token_id_prefix`
- [ ] `src/Persistence/Server/Repositories/AuthCodeRepository.php:L50-L65` — info — persist auth code — `client_id` (no `code`)
- [ ] `src/Persistence/Server/Repositories/AuthCodeRepository.php:L67-L80` — info — revoke auth code — `code_id_prefix`
- [ ] `src/Persistence/Server/Repositories/ScopeRepository.php:L30-L40` — debug — unknown scope `orElse(null)` — `scope`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L118-L120` — debug — skip pvh (empty aud) — `reason=missing_aud`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L152-L155` — debug — audience `orElseGet` session — `aud`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L160-L166` — debug — unknown audience client — `aud`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L168-L175` — error — ORN register fallback (exists) — `client_id`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L183-L185` — debug — login id fallback chain — `user_id`, `login_id`
- [ ] `src/Models/AuthorizationJwtAssembler.php:L230-L237` — debug — metadata `orElse(null)` — `client_id`, `login_id`
- [ ] `src/Models/AmtgardIdpJwt.php:L30-L51` — info — mint + Redis seed — `user_uuid`, `aud` (no jwt)
- [ ] `src/Utility/UserAuthority.php:L25-L35` — error — non-admin fallback (exists); add `user_uuid` — `user_uuid`, `fallback=non_admin`
- [ ] `src/Utility/IamServiceFormatParser.php:L17-L18` — debug — empty → default format — `reason=default_format`
- [ ] `src/Utility/IamServiceFormatParser.php:L22-L29` — error — invalid format throw — `rule=iam_service_format`
- [ ] `src/Utility/IamServiceFormatValidator.php:L11-L12` — debug — empty format — `reason=empty_format`
- [ ] `src/Utility/ClientMetadata/ClientMetadataEncodingRegistry.php:L34-L35` — debug — bad encoding — `rule=encoding`
- [ ] `src/Utility/ClientMetadata/JsonObjectMetadataEncodingStrategy.php:L18-L19` — debug — not object — `rule=json_object`
- [ ] `src/Utility/ClientMetadata/Base64MetadataEncodingStrategy.php:L18-L32` — debug — base64/json throws — `rule`
- [ ] `src/Utility/ClientMetadata/MetadataSizeGuard.php:L13-L16` — debug — size — `payload_len`, `max_bytes`
- [ ] `src/Utility/Pvh.php:L54-L57` — debug — invalid policy hash hex — `rule=policy_hash_hex`
- [ ] `src/Utility/Pvh.php:L107-L124` — debug — encode/assert throws — `rule`
- [ ] `src/Utility/PvhCacheRecord.php:L67-L90` — warning — JSON/schema miss → null (treat as cache miss) — `reason=corrupt_pvh_record`
- [ ] `src/Utility/OAuthKeyMaterial.php:L15-L32` — emergency — missing/unreadable key file — `env_key` (no path contents, no PEM)

**M8 count: 51**

---

## M9 — Phase 3 hardening: management, ORK, session infra

- [ ] `src/Controllers/Management/ManagementController.php:L50-L66` — info — cleanup success (start exists) — `tokens_cleaned=true`
- [ ] `src/Controllers/Management/ManagementController.php:L67-L70` — critical — cleanup 500 (exists) — `exception_class`
- [ ] `src/Controllers/Management/ManagementController.php:L102-L124` — info — create OAuth client — `client_id`, `is_confidential`, `iam_service` (no `client_secret`)
- [ ] `src/Controllers/Management/ManagementController.php:L127-L145` — warning — update missing client id — `id`
- [ ] `src/Controllers/Management/ManagementController.php:L132-L149` — info — update client — `client_id`
- [ ] `src/Utility/Client/ClientIamAdminInput.php:L23-L32` — debug — admin IAM field normalize — `has_iam_service`, `has_format`
- [ ] `src/Services/OrkService.php:L27-L28` — emergency — missing ORK UA/referer — `reason=ork_api_config`
- [ ] `src/Services/OrkService.php:L65-L69` — error — authorize fail/exception (exists) — drop `username`; use `email_sha256` if needed
- [ ] `src/Services/OrkService.php:L99-L107` — error — GetPlayer fail (exists)
- [ ] `src/Services/OrkService.php:L154-L164` — error — GetPark fail (exists)
- [ ] `src/Services/OrkService.php:L201-L201` — warning — park lookup empty (exists)
- [ ] `src/Utility/Security/SessionStorage.php:L42-L43` — warning — Redis configured but unreachable → files — `session_handler=files`, `reason=redis_unreachable`
- [ ] `src/Utility/Security/SessionStorage.php:L107-L108` — warning — Redis probe silent catch — `host` (no password)
- [ ] `src/Controllers/HomeController.php:L26-L36` — debug — home render — `logged_in`
- [ ] `src/Controllers/VersionController.php:L12-L17` — debug — version hit — `route=version`
- [ ] `src/Utility/IamServiceValidator.php:L22-L22` — debug — custom prefix validate (throws from OrnClassMap) — `iam_service`
- [ ] `src/Controllers/Resource/ResourcesController.php:L324-L346` — info — RefreshORK (exists)
- [ ] `src/Controllers/Client/ConnectController.php:L143-L144` — warning — session regenerate fail (exists)
- [ ] `src/Controllers/Client/ConnectController.php:L244-L245` — warning — session regenerate fail (exists)
- [ ] `src/Services/OrkLinkTokenService.php:L99-L102` — warning — jti replay (exists)
- [ ] `src/Services/OrkLinkTokenService.php:L117-L118` — warning — jti cleanup fail (exists)
- [ ] `src/Persistence/Server/Repositories/AccessTokenRepository.php:L86-L89` — info — expired access purge — `job=clean_tokens`
- [ ] `src/Persistence/Server/Repositories/RefreshTokenRepository.php:L83-L89` — info — expired/orphan refresh purge
- [ ] `src/Persistence/Server/Repositories/AuthCodeRepository.php:L82-L88` — info — expired code purge
- [ ] `src/Utility/JsonResponseBody.php:L14-L22` — info — optional 2xx JSON write if middleware does not summarize — `http_status`
- [ ] `src/Middleware/JsonBodyParserMiddleware.php:L22-L23` — debug — JSON parsed OK — `path`
- [ ] `src/Utility/Redis/PubSubRedisConfig.php:L112-L117` — notice — hostname fallback to 127.0.0.1 — `primary_host`, `fallback_host`
- [ ] `src/Utility/PvhGate.php:L16-L26` — debug — miss seed record built — `user_uuid`, `aud` (no email; hash if needed)

**M9 count: 28**

---

## M10 — Phase 1–2: log-read API and IAM (new files)

No current lines. Add logs at implementation on deny and query bounds (design § Log-read API and IAM).

- [ ] `src/Middleware/LogReaderAuthorizationMiddleware.php` (new) — error — missing `IDP/ReadTraceLogs` / `ReadErrorLogs` / `AnalyzeLogs` — `user_uuid`, `client_id`, `orn`, `route`
- [ ] `src/Services/LogsQueryService.php` (new) — info — query executed — `stream`, `row_count`, `limit`, `request_id_filter`
- [ ] `src/Services/LogsQueryService.php` (new) — error — over-limit / invalid cursor — `reason`, `limit`
- [ ] `src/Controllers/Resource/LogsController.php` (new) — info — `GET /resources/logs/trace/{request_id}` — `request_id`, `row_count`
- [ ] `src/Controllers/Resource/LogsController.php` (new) — error — 404 unknown `request_id` — `request_id`
- [ ] `src/Controllers/Resource/LogsController.php` (new) — error — 429 rate limit — `user_uuid`, `route`
- [ ] `bin/idp-logs` (new) — info — CLI query — `command`, `row_count` (no `--redact-off`)
- [ ] `bin/logs-purge.php` (new) — notice — purge run — `stream`, `files_deleted`, `cutoff`
- [ ] `src/Models/Orn/IdpFormat.php` (new resources) — notice — register `IDP/ReadTraceLogs` etc. — `resource`
- [ ] `config/container.php` (extend L72-L86) — emergency — SQLite/log dir unwritable at boot — `log_root`

**M10 count: 10**

---

## Optional / `orElseThrow` / `orElseGet` index (top sites)

Design spike acceptance: catalog auth/JWT/client IAM Optionals.

| Site | Kind | Milestone |
|------|------|-----------|
| `CachedJwtLocalIdpAuthMiddleware.php:L39-L47` | `orElseThrow` 401 ×4 | M3 |
| `ClientRestrictedAuthMiddleware.php:L44-L47` | `orElseThrow` 401 ×4 | M3 |
| `ConfidentialClientAuthenticator.php:L45-L59` | `orElseThrow` 401 ×2 | M2 |
| `UserPolicyClaimWriter.php:L65-L66` | `orElseThrow` 400 | M8 |
| `JwtPvhRefreshService.php:L94-L100` | `orElseGet` user missing | M4 |
| `ClientResourcesController.php:L237-L239` | `orElseGet` 404 | M7 |
| `ClientResourcesController.php:L371-L376` | `orElseGet` 400/404 | M7 |
| `ClientResourcesController.php:L417-L422` | `orElseGet` 400/404 | M7 |
| `AuthController.php:L94-L96` | `orElse(null)` login | M6 |
| `ConnectController.php:L90-L92` | `orElse(null)` login | M6 |
| `Google/Discord/Facebook/Apple *AuthController` | `orElseGet` provision | M6 |
| `AuthorizationJwtAssembler.php:L152-L237` | audience/login/metadata fallbacks | M8 |
| `ClientRepository.php:L91-L106` | `orElse(null/false)` | M8 |
| `CurrentUserResolver.php:L30-L32` | `orElse(null)` | M7 |
| `PvhQueueMessage.php:L33-L54` | Optional blank | M4 |
| `UserLoginRepository.php:L46-L48` | `orElse(false)` | M8 |
| `UserLoginClientRepository.php:L33-L41` | `orElse(null)` | M8 |
| `OAuthAccessTokenElevationMiddleware.php:L48-L50` | `orElse(false)` deny | M2 |
| `LocalIdpAuthMiddleware.php:L49-L50` | `orElse(false)` deny | M2 |

---

## Catch blocks without a log (must add)

| Site | Today | Level when implementing |
|------|-------|-------------------------|
| `LocalIdpAuthMiddleware.php:L64-L66` | empty catch | warning |
| `OAuthAccessTokenFallback.php:L31-L32` | empty catch | error |
| `OAuthTokenAction.php:L29-L30` | generateHttpResponse only | error |
| `OAuthApproveAction.php:L43-L51` | renderer, no local oauth 4xx line | error |
| `PvhQueueMessage.php:L53-L54` | empty catch | error |
| `PvhCacheRecord.php:L71-L72` | empty catch | warning |
| `SessionStorage.php:L107-L108` | empty catch | warning |
| `PubSubRedisConfig.php:L82-L84`, `L143-L144` | swallow / rethrow later | warning / critical |
| `UserOrkProfileRepository.php:L26-L29` | date → null | debug |
| `ClaimOrnValidator.php:L28-L31` | wrap only | error at writer + debug here |
| `JsonBodyParserMiddleware.php:L21-L24` | no catch; `json_last_error` ignored | error |
| `ApiAwareErrorHandler.php` | Slim parent only | critical/error per HTTP map |
| `ClientResourcesController.php:L73-L74`, `L124-L125`, `L201-L202`, `L448-L449` | 400 return, no log | error |
| `ResourcesController.php:L291-L292` | 302, no log | error |
| `ResourcesController.php:L416-L418` | 400, no log | error |

Catches that **already log** (still review level): `ConnectController`, `OrkLinkTokenService`, `OrkService`, `UserPolicy*`, `UserAuthority`, `AuthorizationJwtAssembler`, `OAuthSocialCallbackHandler`, `OAuthAuthorizeAction` League branch, `OAuthFlowErrorRenderer`, `ManagementController::cleanTokens`, `jwt-pvh-worker.php`, `Jwt.php` signature.

---

## Implementation notes

1. Prefer `LogOptional::orElseThrowLogged` (design) at M3 sites instead of duplicating messages.
2. `HttpObservabilityMiddleware` (M1) emits one **info** (2xx/3xx), **notice** (404), **error** (other 4xx), **critical** (5xx) line per request; handler logs above are the detail stream.
3. Existing `notice` on `/resources/validate` 409/401 must move to **error** (stakeholder: 4xx → error).
4. `LowLatencyController` currently logs raw `pvh` hex — redact to prefix/hash at implementation.
5. `ConnectController` and `OrkService` log raw `email` / `username` — replace with `email_sha256`.
6. `NotFoundErrorHandler` may log management `?key=` in `query` — redact before rollout.
7. Line numbers are relative to baseline `9980cf5`. Re-grep if the branch moves.

---

*End of instrumentation checklist.*
