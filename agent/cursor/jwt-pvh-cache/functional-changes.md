# Functional changes — JWT PVH cache

One entry per functional (or milestone-required documentary) change. Newest milestone at the top of each group.

---

## M7 — Remove serialize JWT cache stopgap (JSON pvh only)

- **Milestone:** M7
- **File(s):** `src/Persistence/Server/Repositories/RedisCacheRepository.php`, `src/Utility/CachedValidatedUserEntity.php` (deleted), `src/Controllers/Resource/ResourcesController.php`, `tests/Persistence/RedisCacheRepositoryTest.php`, `tests/Controllers/ResourcesControllerTest.php`, `tests/Utility/UtilityClassesTest.php`, `tests/Controllers/LowLatencyControllerTest.php`, `tests/Middleware/CachedJwtLocalIdpAuthMiddlewareTest.php`, `tests/Middleware/ClientRestrictedAuthMiddlewareTest.php`
- **Prior state:** `getUser`/`setUser`/`cacheValidatedUser`/`isUserInCache` still `serialize()`d `CachedValidatedUserEntity` at Redis key=`userId`. `/resources/jwt` still wrote that leftover after M5 stopped reading it. M2 noted the path would remain until M7.
- **Post state:** Those methods and `CachedValidatedUserEntity` are gone. `getJwt` only mints via `buildAuthorizationTokens` (assembler already `setPvhRecord` JSON). Cache API is `getPvhRecord`/`setPvhRecord` JSON only. `invalidateUser` remains logout-only: SCAN-deletes `pvh:{uuid}:*` and DELs the leftover UUID key so pre-M7 serialize blobs do not survive logout. OAuth session `serialize($authRequest)` in `OAuth2ServerController` is unchanged (not the pvh cache).
- **Reasoning:** After M5, nothing authorized on the serialize blob. Keeping it forked a second cache that could be `DEL`d or recached independently of `pvh`.
- **Security impact:** Logout still drops pvh keys. No HTTP caller reads `unserialize` of a JWT cache blob. Stolen-token window is still the pvh free-hit / worker rotate path.

## M7 — README production: isolated worker next to sessions Redis

- **Milestone:** M7
- **File(s):** `README.md`
- **Prior state:** Production section lumped “MySQL and the shared Redis container” and described the worker in a later paragraph.
- **Post state:** Production states MySQL is host-owned (no prod MySQL Docker volume). Sessions Redis (`amtgard-idp-sessions`) and `amtgard-idp-jwt-worker` are listed as isolated Compose projects that stay up across slot switch. Config documents `REDIS_PVH_QUEUE_NAME`.
- **Reasoning:** Operators must not treat the worker as a blue/green slot or assume a Docker MySQL volume in prod.
- **Security impact:** none (docs). Same trust boundary already shipped in M4 (`host.docker.internal` + Redis DB 0).

## M7 — Milestone checklist

- **Milestone:** M7
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M7 items unchecked.
- **Post state:** M7 items checked. M8 (human merge/deploy) not started.
- **Reasoning:** Orchestration bookkeeping. Ready for PR; do not merge to main here.
- **Security impact:** none

## M6 — Compact RS256 heartbeat JWT from `/resources/jwt`

- **Milestone:** M6
- **File(s):** `src/Models/AmtgardIdpJwt.php`, `src/Models/AuthorizationJwtAssembler.php`, `src/Controllers/Resource/ResourcesController.php`, `tests/Models/ModelsTest.php`, `tests/Controllers/ResourcesControllerTest.php`, `tests/Controllers/LowLatencyControllerTest.php`, `tests/Utility/CompactJwtSizeTest.php`, `templates/api.md`
- **Prior state:** `GET /resources/jwt` returned only `{jwt}` (fat RS256). Validate already compared a presented `pvh` claim but clients had no compact token to send. Docs called compact “upcoming.”
- **Post state:** One mint writes fat + compact from the same claims (`exp`/`pvh`/`iat` copied). Response is `{jwt, compact_jwt}`. Compact claims are only `sub`, `aud`, `iss`, `exp`, `pvh`, `iat` — no policy/email/orkid/challenge/client_metadata. Same RS256 keys; `validateJwtSignature` unchanged. Validate 200s a compact-only Bearer when Redis current `pvh` matches. Measured compact size **627** bytes (`goldens/compact-jwt-size.md`). `?jwt=1` documented as temporary compat.
- **Reasoning:** Fat policy JWT is too large for a cookie-less heartbeat under one Ethernet MTU. Compact keeps the generation id on the wire without shrinking integrator `jwt`.
- **Security impact:** Compact is still RS256 (not HS256). It carries no policy — authorization for userinfo/IAM stays on the fat token. Validate already authorized on `pvh` + sig + `sub`/`aud`/`iss`/`exp`; compact does not extend `exp`. Stolen compact works in the same free-hit window as a stolen fat token with current `pvh`.

## M6 — Milestone checklist

- **Milestone:** M6
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M6 items unchecked.
- **Post state:** M6 items checked. M7 (proof/cleanup) not started.
- **Reasoning:** Orchestration bookkeeping.
- **Security impact:** none

## M5 — Shared `PvhGate` for 200 / 409 / 401

- **Milestone:** M5
- **File(s):** `src/Utility/PvhGate.php`, `src/Utility/PvhAccess.php`, `src/Controllers/Resource/LowLatencyController.php`, `tests/Utility/PvhGateTest.php`
- **Prior state:** `LowLatencyController` owned the pvh/prev_pvh/fat-hash-prefix compare and wrote 409 `{error:"stale_token"}` inline. Middleware used `isUserInCache`.
- **Post state:** `PvhGate::evaluate` / `evaluatePresented` returns `PvhAccess` (`Current` / `Previous` / `Unknown` / `Miss`). Fat JWTs without `pvh` still match `policy_hash` prefix to `hashPrefixHex`. `writeStaleToken` / `staleTokenResponse` emit 409 JSON. Validate uses the gate for the hit table and still seeds on `Miss` only.
- **Reasoning:** One 409/401 table (D13) for validate and both Bearer middlewares. Avoid forked compare that would accept a stolen fat JWT on one path and reject it on another.
- **Security impact:** Compare itself is unchanged from M3 validate. Sharing it is hygiene so userinfo/middleware cannot skip pvh or treat cache presence as success.

## M5 — Middleware gates on pvh; cache miss is 401

- **Milestone:** M5
- **File(s):** `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php`, `src/Middleware/ClientRestrictedAuthMiddleware.php`, `tests/Middleware/CachedJwtLocalIdpAuthMiddlewareTest.php`, `tests/Middleware/ClientRestrictedAuthMiddlewareTest.php`
- **Prior state:** Both used `isUserInCache($sub)` as success. Cache miss set session and `cacheValidatedUser` (ClientRestricted also called `ResourceServer::validateAuthenticatedRequest` — OAuth access-token API — with the RS256 authorization JWT). Session was set before the cache check on CachedJwt.
- **Post state:** `getPvhRecord(sub, aud)` + `PvhGate`. Current (or fat hash-prefix match) → set session, handle. Previous → 409 `{error:"stale_token"}` (JSON response, not `HttpUnauthorizedException`). Miss or unknown → 401. No seed, no `cacheValidatedUser`, no `isUserInCache`. ClientRestricted keeps the session allowlist short-circuit; Bearer path never calls `validateAuthenticatedRequest`.
- **Reasoning:** “User key exists” was not a generation check (D11). Session must be set only after current pvh. userinfo must not bootstrap a generation from a stolen token that never passed validate. ResourceServer is for OAuth access tokens, not authorization JWTs.
- **Security impact:** **Authz behavior change.** A valid RS256 JWT is no longer enough for userinfo: Redis must have a current `pvh` for that `aud`. Stolen token that never hit validate cannot seed via userinfo (401). One-generation-behind is 409 with no JWT body. Unknown pvh is 401 (no fishing). Session cookie is still enough for ClientRestricted allowlisted `client_id` (unchanged first-party short-circuit).

## M5 — `userinfo` returns profile only (D11)

- **Milestone:** M5
- **File(s):** `src/Controllers/Resource/ResourcesController.php`, `tests/Controllers/ResourcesControllerTest.php`
- **Prior state:** Every successful userinfo called `buildAuthorizationJwt` and returned `{id, email, jwt, ork_profile?}`. Goldens recorded that remint.
- **Post state:** `{id, email, ork_profile?}` only. `buildAuthorizationJwt` does not run on this path. Unauthenticated still 401. `/resources/jwt` remains the remint well and still rejects authorization JWTs (`OAuthAccessTokenElevationMiddleware` unchanged). `Jwt::validateJwtSignature` algorithm unchanged.
- **Reasoning:** Authorization JWT is not a refresh token. Possession of a stale Bearer must not extend `exp` or pick up current policy without an access token/session at `/resources/jwt`.
- **Security impact:** **Closes the remint-as-refresh hole.** A stolen authorization JWT can still read profile while `pvh` is current (same window as validate 200). It cannot obtain a new JWT from userinfo or from 409. Cache miss cannot bootstrap.

## M5 — OpenAPI + `templates/api.md` remint well / 409 / compact upcoming

- **Milestone:** M5
- **File(s):** `src/Controllers/Resource/ResourcesController.php` (OA), `templates/api.md`
- **Prior state:** userinfo OpenAPI required a reminted `jwt` and 401 only. Docs showed userinfo echoing/refreshing the JWT and validate 200 including `jwt`.
- **Post state:** userinfo OA: `id` string, no `jwt` property, 409 `stale_token`. getJwt OA: remint well; does not accept authorization JWTs. Docs: remint well is GET `/resources/jwt`; validate 200 omits `jwt` (`?jwt=1` presented only); compact heartbeat mentioned as upcoming/M6.
- **Reasoning:** Integrators must handle 409 + remint at `/resources/jwt`, not treat userinfo as token refresh.
- **Security impact:** none (docs/contract). Clients that decoded `userinfo.jwt` must switch to the token from `/resources/jwt`.

## M5 — Milestone checklist

- **Milestone:** M5
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M5 items unchecked.
- **Post state:** M5 items checked. M6 (compact heartbeat) not started.
- **Reasoning:** Orchestration bookkeeping.
- **Security impact:** none

## M4 — Isolated JWT PVH worker refreshes MySQL + Redis pvh

- **Milestone:** M4
- **File(s):** `src/Services/JwtPvhRefreshService.php`, `src/Models/AuthorizationJwtAssembler.php`, `src/Utility/CallConsumersBackoff.php`, `bin/jwt-pvh-worker.php`, `config/bootstrap.php`, `public/index.php`, `tests/Services/JwtPvhRefreshServiceTest.php`, `tests/Utility/CallConsumersBackoffTest.php`
- **Prior state:** Validate 200 enqueued `{user_uuid, aud}` on `REDIS_PVH_QUEUE_NAME`. No consumer. Policy hash / generation rotation existed only on `/resources/jwt` mint.
- **Post state:** CLI worker bootstraps the same PHP-DI container as FPM. On boot: `redrive` then `subscribe` + `callConsumers` loop with D17 backoff (0→1→2→…→100ms; hit resets to 0). Subscriber decodes JSON, `JwtPvhRefreshService` loads the user, reuses assembler ORN + `toPolicyJson` + canonical metadata, compares `policy_hash`. Equal → no Redis write. Differ → repo upsert (rotates `prev_pvh`) + `setPvhRecord`. Does not mint or sign a JWT. Processing failures log and **re-publish** the same key/message (library always `commit()`s; throw cannot withhold ack). SIGTERM finishes the current job and exits 0. CLI `memory_limit` 256M (not FPM 32M).
- **Reasoning:** Freshness probe after the free validate hit (D2). Worker must not remint into an HTTP JWT (D1). Same hash path as mint so ORN registration bugs are not forked (D7).
- **Security impact:** Worker is a privileged backend writer of generation + Redis pvh. No new public HTTP surface. Failure re-publish is at-least-once (duplicate rotate is idempotent on the same hash). Malformed payloads are dropped (not looped). Stolen-token window is unchanged: next successful worker pass after a claim change produces 409 on the old `pvh`.

## M4 — Worker container is not a blue/green slot (D5)

- **Milestone:** M4
- **File(s):** `docker/compose.worker.yml`, `install.sh`, `docker/compose.dev.yml`, `README.md`
- **Prior state:** Prod had blue/green app slots + isolated `amtgard-idp-sessions`. No worker project. Dev had no worker service.
- **Post state:** Compose project `amtgard-idp-worker`, `container_name: amtgard-idp-jwt-worker`, `restart: unless-stopped`, shared `amtgard-idp-shared` network, `.env`/`keys`/`dev-keys` binds, `host.docker.internal`, command is PHP CLI worker (not `heartbeat.sh`). `install.sh` `ensure_jwt_worker` runs after migrate on the inactive slot and before health check / nginx switch: `docker tag` the slot image to `amtgard-idp-jwt-worker:latest` then `compose_worker up -d`. Worker is not stopped on slot switch. `INSTALL_REBUILD_WORKER=1` force-recreates it; `INSTALL_REBUILD_SESSIONS` does not. Dev: `jwt-worker` profile service (shares app network for in-container Redis) plus documented `docker compose exec amtgardidpapp php bin/jwt-pvh-worker.php`.
- **Reasoning:** Same durability story as sessions Redis (D5). Worker image matches the schema just migrated. Recreate is independent of nginx cutover so the queue keeps draining.
- **Security impact:** Worker reaches MySQL via `host.docker.internal` and Redis DB 0 on the sessions host — same trust boundary as the app slot. No extra published ports.

## M4 — Milestone checklist

- **Milestone:** M4
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M4 items unchecked.
- **Post state:** M4 items checked. M5 (userinfo/middleware pvh) not started.
- **Reasoning:** Orchestration bookkeeping.
- **Security impact:** none

## M3 — `GET /resources/validate` serves pvh cache (D1, D2, D10, D12, D13)

- **Milestone:** M3
- **File(s):** `src/Controllers/Resource/LowLatencyController.php`, `src/Utility/Jwt.php`, `tests/Controllers/LowLatencyControllerTest.php`
- **Prior state:** Validate required PHP session `user_id === sub` (401 otherwise). Compared the presented JWT to a serialized `CachedValidatedUserEntity` via `Jwt::validateJwt` (aud/iss/exp + `Policy::is` walk). Cache miss recached the presented JWT (`setUser` serialize). 200 body was `{id, email, jwt}` with the cached token. Presence publish ran on 200. `queueUserValidation` was not called. No 409. OpenAPI documented `jwt` as required and 401 only.
- **Post state:** Auth is RS256 (`Jwt::getBearerJwt` + `validateJwtSignature` + `parseJwt`) plus required `sub`/`aud` and `iss === https://idp.amtgard.com`. No session check. Presented `pvh` (44-char hex) is compared to Redis `pvh:{sub}:{aud}`; fat JWTs without `pvh` compare `policy_hash` prefix to `hashPrefixHex(cached.pvh)` / prev. Table: bad sig / bad iss / expired → 401 no enqueue no write; hit current → 200 `{id, email}` + `queueUserValidation(sub,aud)` no write; hit prev → 409 `{error:"stale_token"}` no enqueue; hit neither → 401; miss + otherwise valid → 200, seed current=presented or `Pvh::encode(nowMs, policyHash)`, prev=null, enqueue. `?jwt=1` echoes the **presented** token only. Presence `publish(handle, userId, email)` unchanged on 200. Does not call `Jwt::validateJwt`, `setUser`, or `cacheValidatedUser`.
- **Reasoning:** Authorization JWT is not a refresh token (D1). Session cookie fought MTU (D12). One-generation 409 is the do-over; unknown pvh is 401 (D13). Enqueue only on 200 so the worker (M4) refreshes after the free hit (D2). Fat tokens stay valid during compatibility (D10).
- **Security impact:** **Authz behavior change.** Possession of a valid RS256 JWT is enough for validate; a stolen Bearer no longer also needs the PHP session cookie. Stolen fat JWT still works until `exp` **and** until the free hit is consumed (accepted eventual-consistency window). 409 never remints and never returns a JWT. 401/409 never enqueue. Unknown pvh is 401 (no fishing). RS256 algorithm and `LooseValidAt` exp check are unchanged. `Policy::is` is no longer an authorization gate on this path — generation id is. Issuer is now a hard compare to the assembler constant (previously iss was only compared to the cached token’s iss, and test tokens used `http://localhost`).

## M3 — Fat JWT mint adds `pvh`; remint upserts generation + Redis JSON

- **Milestone:** M3
- **File(s):** `src/Models/AuthorizationJwtAssembler.php`, `src/Models/AmtgardIdpJwt.php`, `src/Utility/Pvh.php`, `tests/Models/ModelsTest.php`
- **Prior state:** Assembler claims had no `pvh`. `AmtgardIdpJwt::buildAuthorizationJwt` encoded assembler claims and returned. `GET /resources/jwt` additionally `cacheValidatedUser` (serialize at user UUID). No MySQL generation write on mint.
- **Post state:** After audience/metadata claims, assembler hashes `aud \n policy JSON \n canonical_metadata` (no `pvh` in the hash), `UserJwtGenerationRepository::upsert` (sticky `pvh` when `policy_hash` matches), and sets claim `pvh` from the row. After RS256 encode, `AmtgardIdpJwt` `setPvhRecord` with `{user_uuid, aud, email, pvh, prev_pvh}` from that row. `getJwt` still `cacheValidatedUser` (legacy key for userinfo middleware until M5). Same `pvh` on remint-with-unchanged-hash; new JWT bytes (`iat`/`exp`/`challenge`).
- **Reasoning:** Remint well must write the durable pointer and Redis current so the next validate can 200/409. Sticky upsert is D7. Additive `pvh` claim; old clients ignore unknown claims. Social/userinfo remint paths that call `buildAuthorizationJwt` also persist generation+pvh Redis (same mint function).
- **Security impact:** New claim is inside the existing RS256 payload — not an unsigned bearer. Remint still requires access token or session (`OAuthAccessTokenElevationMiddleware` unchanged; authorization JWTs still rejected on `/resources/jwt`). Writing Redis on every mint (including userinfo remint) is a cache write, not a new credential. Legacy serialize cache on `getJwt` is unchanged so M5 middleware is not broken early.

## M3 — Client IAM no longer `DEL`s cache on claim/metadata mutation (D3, D4)

- **Milestone:** M3
- **File(s):** `src/Controllers/Resource/ClientResourcesController.php`, `tests/Controllers/ClientResourcesControllerTest.php`
- **Prior state:** `addPolicyClaim`, `deletePolicyClaim`, `upsertUserMetadata`, and `deleteUserMetadata` called `invalidateUserCache` → Redis `DEL` of the user UUID key (and, since M2, SCAN `pvh:{uuid}:*`).
- **Post state:** Those four paths still write/delete MySQL. They do not call `invalidateUser` / `DEL`. `RedisCacheRepository` is no longer a constructor dependency. Logout still calls `invalidateUser` (legacy key + SCAN pvh keys).
- **Reasoning:** `DEL` was the stopgap that recached stale tokens on the next miss (D3). Enqueue-on-mutation would refresh before the next validate and eat the free hit (D4). Logout must still drop cache so a logged-out user is 401, not a 409 loop.
- **Security impact:** **Authz timing change, not a permission change.** After add/revoke/metadata, Redis may still hold the previous `pvh` until a successful validate enqueues the worker (M4) or `/resources/jwt` remints. The next validate with the old token is the free hit (200); the following heartbeat with the same `pvh` is 409 once the worker rotates. Stolen tokens in that window still work — documented accepted window. IAM HTTP JSON keys and DB writes are unchanged. No enqueue on mutation.

## M3 — Logout prefix delete unchanged (M2 already sufficient)

- **Milestone:** M3
- **File(s):** `src/Controllers/Client/AuthController.php` (no code change)
- **Prior state:** Logout called `invalidateUser($sessionUserId)`. M2 already `DEL`s the legacy key and SCAN-deletes `pvh:{userId}:*`.
- **Post state:** Same call. No additional prefix or MySQL generation delete this milestone (next jwt recreates the row).
- **Reasoning:** Design prefers drop Redis; next `/resources/jwt` recreates. Avoid 409-loop after logout: cache miss + no session/access token at remint → 401.
- **Security impact:** none this milestone (logout already cleared pvh keys). Logged-out validate with a still-unexpired JWT would 200-and-seed on cold cache (free hit) — same class of eventual-consistency window as a cold cache miss; remint still requires access token/session.

## M3 — Milestone checklist

- **Milestone:** M3
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M3 items unchecked.
- **Post state:** M3 items checked. Worker (M4) and userinfo/middleware pvh (M5) not started.
- **Reasoning:** Orchestration bookkeeping.
- **Security impact:** none

## M2 — PVH Redis JSON cache beside legacy serialize path

- **Milestone:** M2
- **File(s):** `src/Persistence/Server/Repositories/RedisCacheRepository.php`, `src/Utility/PvhCacheRecord.php`, `tests/Persistence/RedisCacheRepositoryTest.php`
- **Prior state:** Cache was only `getUser`/`setUser`/`cacheValidatedUser` via `serialize()` at key=`userId`. `queueUserValidation` was an empty stub.
- **Post state:** New `getPvhRecord`/`setPvhRecord` store JSON `{user_uuid, aud, email, pvh, prev_pvh}` at `pvh:{userUuid}:{aud}`. Legacy serialize methods are unchanged so LowLatency validate still uses today's path.
- **Reasoning:** M3 needs a typed JSON record without ripping out the serialize cache that existing tests and logout/IAM still depend on.
- **Security impact:** New keys are JSON only (no `unserialize` on the pvh path). Legacy `unserialize` remains until M7.

## M2 — `invalidateUser` deletes legacy key and `pvh:{userId}:*`

- **Milestone:** M2
- **File(s):** `src/Persistence/Server/Repositories/RedisCacheRepository.php`
- **Prior state:** `invalidateUser($userId)` only `DEL`ed the legacy key `$userId`.
- **Post state:** Still `DEL`s `$userId` (logout/Client IAM keep clearing today's cache) **and** SCAN-deletes `pvh:{userId}:*` so future pvh records are cleared on the same call.
- **Reasoning:** Logout must not leave audience-scoped pvh rows after M3 starts writing them. IAM claim paths still call this in M2 (M3 removes those `DEL`s).
- **Security impact:** Broader delete on logout is intended. SCAN pattern uses the user UUID; UUID charset is not a glob injection.

## M2 — `queueUserValidation` publishes on the dedicated PVH SetQueue

- **Milestone:** M2
- **File(s):** `src/Persistence/Server/Repositories/RedisCacheRepository.php`
- **Prior state:** Empty stub `queueUserValidation(string $userId, string $userEmail)`. Nothing called it.
- **Post state:** Signature is `(string $userUuid, string $aud)`. Publishes via v1.1.2 `PubSubQueue::publish($queueName, $key, $message)` with key `{userUuid}:{aud}` and JSON `{"user_uuid","aud"}` on `REDIS_PVH_QUEUE_NAME`. Not called from LowLatency this milestone.
- **Reasoning:** Plumbing only; M3 will enqueue on validate 200. Presence `publish(userId, email)` on `REDIS_PUBSUB_QUEUE_NAME` is untouched (D8).
- **Security impact:** none this milestone (no HTTP caller). Payload is identifiers only, no JWT.

## M2 — Dedicated PVH SetQueue in PHP-DI

- **Milestone:** M2
- **File(s):** `config/container.php`, `src/Utility/PvhSetQueue.php`, `src/Utility/PvhQueueHandle.php`, `tests/Config/ContainerPubSubRedisWiringTest.php`
- **Prior state:** One `SetQueue::class` + `PubSubQueueHandle` registered on the shared `PubSubQueue` for presence.
- **Post state:** `PvhSetQueue` (subclass) and `PvhQueueHandle` are separate bindings. `PvhQueueHandle` factory `addQueue(pvhName, pvhSetQueue)` on the same `PubSubQueue`. Presence `SetQueue::class` / `PubSubQueueHandle` unchanged.
- **Reasoning:** PHP-DI cannot bind two `SetQueue::class` instances. A dedicated type keeps presence and refresh queues from overloading each other (D8).
- **Security impact:** none (wiring only). Wrong-queue publish would have mixed liveness with refresh; separate types prevent that.

## M2 — `REDIS_PVH_QUEUE_NAME` config

- **Milestone:** M2
- **File(s):** `src/Utility/Redis/PubSubRedisConfig.php`, `.env.example`, `tests/Utility/Redis/PubSubRedisConfigTest.php`
- **Prior state:** Only `REDIS_PUBSUB_QUEUE_NAME` (default `amtgard-idp`).
- **Post state:** `PubSubRedisConfig::pvhQueueName()` reads `REDIS_PVH_QUEUE_NAME` default `amtgard-idp-pvh`. `.env.example` documents it as the refresh queue, not presence.
- **Reasoning:** Worker (M4) and publishers must share one env name.
- **Security impact:** none

## M2 — Milestone checklist

- **Milestone:** M2
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M2 items unchecked.
- **Post state:** M2 items checked. Validate/jwt/userinfo still old behavior; `queueUserValidation` is not called from LowLatency.
- **Reasoning:** Orchestration bookkeeping for the stacked implementation.
- **Security impact:** none

## M1 — `Pvh` primitive (sticky generation id)

- **Milestone:** M1
- **File(s):** `src/Utility/Pvh.php`, `tests/Utility/PvhTest.php`
- **Prior state:** No shared helper. Design specified `policy_hash` + time-leading `pvh` but nothing implemented.
- **Post state:** `Pvh` hashes `aud \n Policy::toJson() \n canonical_metadata` as 32 raw SHA-256 bytes. `pvh` is 44-char lowercase hex (6-byte big-endian unix ms + first 16 bytes of the hash). `reuseOrMint` keeps the existing `pvh` when the hash is unchanged. Not wired into JWT mint or validate.
- **Reasoning:** M3/M4 need one canonical encoder so remint and the worker agree on sticky timestamps (D7) without each inventing a hash.
- **Security impact:** none this milestone (helper unused on HTTP). Future compact/fat JWTs will still be RS256; `pvh` is not an unsigned bearer.

## M1 — `user_jwt_generations` table

- **Milestone:** M1
- **File(s):** `db/migrations/20260904200000_create_user_jwt_generations.php`
- **Prior state:** No durable current/previous generation pointer. Validate/cache had no MySQL generation row (D9/D16).
- **Post state:** Phinx table with `user_id`, `user_uuid`, nullable `client_id`, `aud`, `pvh`, `prev_pvh`, `policy_hash` BINARY(32), `updated_at`. UNIQUE `(user_uuid, aud)`, INDEX `pvh`, INDEX `user_id`. No JWT blob, no serialize.
- **Reasoning:** Worker and `/resources/jwt` (later milestones) need an IDP-owned current pointer. Validate stays Redis.
- **Security impact:** none this milestone (table unused on HTTP). BINARY hash is not a secret; it is a content fingerprint.

## M1 — Generation entity/repository upsert

- **Milestone:** M1
- **File(s):** `src/Persistence/Server/Entities/Repository/UserJwtGeneration.php`, `src/Persistence/Server/Repositories/UserJwtGenerationRepository.php`, `tests/Persistence/UserJwtGenerationRepositoryTest.php`, `config/container.php`, `tests/Config/ContainerRepositoryWiringTest.php`
- **Prior state:** No ORM type for generations. Container had no `UserJwtGenerationRepository` binding.
- **Post state:** Entity/repo follow `#[EntityOf]`/`#[RepositoryOf]`. `findByUserUuidAndAud`; `upsert` leaves `pvh` when `policy_hash` matches, else `prev_pvh ← pvh` and mints a new `pvh`. Container resolves the repo from EntityManager. Not injected into controllers yet.
- **Reasoning:** Same Active Record pattern as `user_login_client`. Sticky upsert is the D7 no-op the worker will call.
- **Security impact:** none this milestone (no HTTP caller). Unique `(user_uuid, aud)` prevents duplicate current pointers.

## M1 — Milestone checklist

- **Milestone:** M1
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M1 items unchecked.
- **Post state:** M1 items checked. Validate/jwt/userinfo still old behavior.
- **Reasoning:** Orchestration bookkeeping for the stacked implementation.
- **Security impact:** none

## M0 — Pre-change goldens recorded

- **Milestone:** M0
- **File(s):** `agent/cursor/jwt-pvh-cache/goldens/pre-change.md`
- **Prior state:** No checked-in fixture of fat JWT claims, validate 200 body, userinfo remint, `/resources/jwt` body, or Client IAM 204+`invalidateUser`.
- **Post state:** Goldens document those shapes with file/line citations. No JWT bytes copied.
- **Reasoning:** Later milestones must diff HTTP and claim behavior against today’s contract without guessing.
- **Security impact:** none

## M0 — Set-queue 1.1.2 method names locked

- **Milestone:** M0
- **File(s):** `src/Utility/Redis/PubSubRedisConfig.php`
- **Prior state:** Class documented Redis host/port/db/queue env only. Design pack D14 still said `pump` / “confirm publish vs send”.
- **Post state:** Class docblock locks v1.1.2 API: `publish`, `addQueue`, `subscribe`, `redrive`, `callConsumers`. Notes that `send`/`pump` are README-only and that the library `commit()`s even after subscriber exceptions. No executable change.
- **Reasoning:** Worker and refresh-queue code in later milestones must call methods that exist on the locked pin. Verified against `vendor/amtgard/redis-set-queue/src/PubSubQueue.php` (composer.lock `86ac6f37cc93d5105c7eb1a92830943a977de399`, tag v1.1.2).
- **Security impact:** none

## M0 — Design pack D14/`pump` wording corrected

- **Milestone:** M0
- **File(s):** `agent/cursor/jwt-pvh-cache/detailed-design.md`, `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** D14/D15 and worker sections used README names `pump` / `send`. D17 backoff was already specified but wrapped a nonexistent `pump` loop. Worker exception note said “do not ack.”
- **Post state:** D14 locks `publish` + `callConsumers`. D15/§4/§6/file map and M4 checklist use `callConsumers`. D17 unchanged (0/1/2/…/100ms around `callConsumers`; sleep 0 after a hit). Worker note updated: library always `commit()`s after failure handlers.
- **Reasoning:** Implementers would have written `$pubSub->pump()` / `send()` which fatals on 1.1.2. Ack behavior must be honest so M4 does not assume throw-to-redrive.
- **Security impact:** none (docs only; no validate/jwt/userinfo/signature change)

## M0 — Milestone checklist

- **Milestone:** M0
- **File(s):** `agent/cursor/jwt-pvh-cache/milestones.md`
- **Prior state:** M0 items unchecked; branch listed as “from current main.”
- **Post state:** M0 items checked. Stacked branch name `stack/jwt-pvh-m0` recorded (base `feature/isolated-prod-blue-green`).
- **Reasoning:** Orchestration bookkeeping for the stacked implementation.
- **Security impact:** none
