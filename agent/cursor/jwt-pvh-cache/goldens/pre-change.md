# Pre-change goldens (M0)

Recorded from `stack/jwt-pvh-m0` against current application code. These are **shapes and citations**, not copied JWT bytes. Later milestones diff HTTP/claim behavior against this file.

No validate / jwt / userinfo / Client IAM behavior was changed in M0.

---

## 1. Fat authorization JWT claim set

Mint path: `GET /resources/jwt` → `ResourcesController::getJwt` → `AmtgardIdpJwt::buildAuthorizationJwt` → `AuthorizationJwtAssembler::buildClaims`.

RS256 encode in `src/Models/AmtgardIdpJwt.php` (encode of assembler claims; no extra claims added after `buildClaims`).

### Always present — `AuthorizationJwtAssembler::baseClaims`

`src/Models/AuthorizationJwtAssembler.php` (`baseClaims`):

| Claim | Source |
|-------|--------|
| `iat` | `time()` |
| `sub` | `$user->userId` (public UUID) |
| `iss` | hardcoded `'https://idp.amtgard.com'` |
| `orkid` | `$user->orkUserId` |
| `orkuser` | `$user->username` |
| `email` | `$user->email` |
| `policy` | `$this->userPolicy->toPolicyJson($user, $forClientDbId)` (sorted ORN JSON) |
| `challenge` | `$this->jwtChallenge->createChallenge($user)` (UUID stub) |
| `exp` | `time() + 3600` (1 hour) |

There is **no `pvh` claim** today.

### Conditional — `applyAudienceClaims`

Same file, after `baseClaims`:

| Claim | When |
|-------|------|
| `aud` | Audience resolved from `$oauthClientId` or `$_SESSION['client_id']`; omitted if null/empty |
| `client_metadata` | Present only when both client DB id and login DB id resolve and `UserLoginClientRepository::getMetadataForJwt` returns a value |

### Not in the fat JWT today

`pvh`, compact-only subset. Compact heartbeat is a later milestone.

---

## 2. `GET /resources/validate` — 200 JSON

Controller: `src/Controllers/Resource/LowLatencyController.php` (`validate`).
Test lock: `tests/Controllers/LowLatencyControllerTest.php` (`testValidateSuccess`).

### Preconditions for 200 (today)

1. `Authorization: Bearer` present; `Jwt::validateJwtSignature` succeeds.
2. PHP session `user_id` is set and **equals** JWT `sub` (string compare). Missing session or mismatch → 401.
3. Redis cache hit **or** miss-then-seed: miss builds `CachedValidatedUserEntity` from session/payload and `setUser` (serialize).
4. `Jwt::validateJwt($presented, $cachedJwt)` — `aud`/`iss` match, `exp` in the future, `policy` identical or `Policy::is()`.

### 200 body (actual runtime, not OpenAPI `id: integer`)

```json
{"id": "<user UUID string>", "email": "<string>", "jwt": "<cached RS256 JWT>"}
```

- `id` is `CachedValidatedUserEntity::getUserId()` (JWT `sub` / session `user_id`), a UUID string.
- `jwt` is the **cached** token (`$user->getJwt() ?? $challengeJwt`), not a remint.
- Content-Type: `application/json`.

### Side effects on 200

- Presence publish: `PubSubQueue::publish($handle, $userId, $email)` where `$handle` is `PubSubRedisConfig::queueName()` (`config/container.php` + `LowLatencyController`).
- `queueUserValidation()` is an empty stub (`RedisCacheRepository`) and is **not** called from validate.

OpenAPI on the same method documents `{id, email, jwt}` and 401 only. No 409 today.

---

## 3. `GET /resources/jwt` — remint well (pre-change)

`src/Controllers/Resource/ResourcesController.php` (`getJwt`).

Auth: `OAuthAccessTokenElevationMiddleware` (access token or session; authorization JWTs rejected — existing product rule).

200 body:

```json
{"jwt": "<newly minted RS256 fat JWT>"}
```

Side effect: `RedisCacheRepository::cacheValidatedUser($userId, $email, $jwt)` — serialize entity at Redis key = user UUID. No MySQL generation row. No `pvh`.

---

## 4. `GET /resources/userinfo` — remints today

`src/Controllers/Resource/ResourcesController.php` (`userinfo`).
Auth middleware: `CachedJwtLocalIdpAuthMiddleware` — requires a signed authorization JWT; cache presence of `sub` short-circuits without comparing policy/`pvh` (`isUserInCache`).

On every success the controller calls `buildAuthorizationJwt` again (remint):

```json
{"id": "<user UUID>", "email": "<string>", "jwt": "<newly minted fat JWT>", "ork_profile": { ... optional ... }}
```

`ork_profile` is omitted when no ORK profile row exists. This remint-on-userinfo is the refresh-credential leak called out in the architecture pack; M5 removes it.

---

## 5. Client IAM add claim — 204 + `invalidateUser`

`POST /resources/client/policy-claims` → `ClientResourcesController::addPolicyClaim`.
Test lock: `tests/Controllers/ClientResourcesControllerTest.php` (`testAddPolicyClaimReturns204AndInvalidatesCache`).

Success path:

1. `UserPolicyClaimRepository::addClaim(...)`.
2. `$this->invalidateUserCache($user)` → `RedisCacheRepository::invalidateUser($user->getUserId())` → Redis `DEL` of the user-UUID key (`src/Persistence/Server/Repositories/RedisCacheRepository.php`).
3. `204` empty body (`$response->withStatus(204)`).

Same `invalidateUser` + 204 pattern on delete claim and metadata upsert/delete. M3 removes `DEL` from claim/metadata paths.

---

## 6. Set-queue API pin (cross-link)

See comment on `src/Utility/Redis/PubSubRedisConfig.php` and D14 in `detailed-design.md`.

Locked names (vendor `PubSubQueue` v1.1.2): `publish`, `addQueue`, `subscribe`, `redrive`, `callConsumers`. Not `send`. Not `pump`.
