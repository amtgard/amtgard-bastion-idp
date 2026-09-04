# Detailed design — JWT PVH cache mutation

**Companion:** [architecture.md](./architecture.md) · [milestones.md](./milestones.md)

This is the implementer map: locked decisions, on-disk/on-wire shapes, and which files change.

---

## 1. Decisions (locked)

| ID | Decision | Rationale |
|----|----------|-----------|
| D1 | Validate never remints. 409 `stale_token` with no JWT body | Authorization JWT is not a refresh token; `/resources/jwt` already rejects it |
| D2 | Enqueue SetQueue **only** on validate **200** | Common-path freshness probe; uncommon path remints and writes cache itself |
| D3 | Stop `invalidateUser()` / `DEL` on policy, metadata, and (for JWT cache) treat logout separately | `DEL` was the stopgap; it recaches stale tokens. Logout still drops the user’s cache entries |
| D4 | Mutation (add/delete claim, metadata) does **not** enqueue | Enqueue-on-write would refresh cache before the next validate and eat the free hit |
| D5 | Worker is Compose project `amtgard-idp-worker`, not a blue/green slot | Same durability story as `amtgard-idp-sessions`; keep draining across nginx cutover |
| D6 | Redis cache key is `{userUuid}:{aud}`, not `userId` alone | Policy and `pvh` are per audience |
| D7 | `pvh` timestamp is sticky (generation birth), not `time()` at hash | Worker no-op when claims unchanged |
| D8 | Dedicated refresh queue, keep existing presence publish on `REDIS_PUBSUB_QUEUE_NAME` | Current `publish(userId, email)` is documented liveness; do not overload it |
| D9 | MySQL stores current + previous generation (`pvh`, `prev_pvh`, `policy_hash`), not the JWT blob | Validate stays Redis; worker/remint need a durable pointer and index-friendly `pvh` |
| D10 | Fat JWT on validate remains valid during compatibility; compact is preferred | MTU is a goal, not a flag day |
| D11 | `userinfo` does not remint | Closes the “JWT as refresh” hole; remint well is `/resources/jwt` |
| D12 | Validate does **not** require PHP session `user_id === sub` | Session cookie fights MTU; RS256 + `pvh` compare is the authz. Session remains optional for first-party browsers |
| D13 | One-generation-behind (`presented === prev_pvh`) → 409; anything else mismatched → 401 | Do-over policy without a generic retry budget |
| D14 | Library usage (v1.1.2, lock `86ac6f37cc93d5105c7eb1a92830943a977de399`): publisher = `publish($queueName, $key, $message, $replace = true)` — **not** `send()`. Worker = `addQueue` + `redrive` + `subscribe` + **`callConsumers($queueName, $count = 1)`** loop. There is **no `pump()`**. Upstream README still shows `send`/`pump`; ignore it — `vendor/amtgard/redis-set-queue/src/PubSubQueue.php` is source of truth. Library `commit()`s on success **and** after subscriber-exception failure handlers | Locked in M0 against vendor + composer.lock. Comment on `PubSubRedisConfig` |
| D15 | Worker runtime is a **plain PHP CLI `callConsumers` loop** in `bin/jwt-pvh-worker.php`. Do **not** add Workerman, ReactPHP, RoadRunner, or Swoole for v1 | SetQueue already *is* the queue; Docker `restart: unless-stopped` + SIGTERM is the process manager. Workerman is only a suggested *host* in the (stale) library README |
| D16 | `user_jwt_generations` lives in the **IDP MySQL** (`DB_*` / Phinx), not ORK. New repository beside existing `src/Persistence/Server/Repositories/` | IDP already owns policy, OAuth, and users. ORK is HTTP + shared-secret JWT only |
| D17 | Worker poll: exponential backoff **0 → 1 → 2 → … → 100ms**. Hit: process, reset sleep to **0**. Miss: if last sleep was 0 then 1ms, else `min(100, last * 2)` | Drain a burst at full speed; idle maxes at 10 polls/sec. Never a tight empty loop |

---

## 2. `pvh` and `policy_hash`

Canonical bytes for `policy_hash`:

```
aud \n Policy::toJson() \n canonical_metadata
```

- `Policy::toJson()` is already sorted ORN JSON (stable).
- `canonical_metadata` is the exact JWT `client_metadata` encoding used at mint (empty string if absent). Metadata upsert already conceptually invalidates; it must change `policy_hash`.
- `policy_hash` = raw SHA-256 (store as 32 bytes BINARY or 64-char hex).
- `pvh` = 6-byte big-endian unix **milliseconds** (ULID time field) concatenated with the **first 16 bytes** of `policy_hash`. Encode as 44-char lowercase hex (`12` time + `32` hash) for JWT claims and CHAR columns.

When the worker (or `/resources/jwt`) computes a `policy_hash` equal to the current row, **leave `pvh` unchanged**. When it differs: `prev_pvh ← pvh`, `pvh ← now_ms || trunc(hash)`, `policy_hash ← hash`.

Do not use whole-second unix time as the only uniqueness: two mutations in the same second must still differ via the hash suffix. Milliseconds + 128-bit trunc is enough.

JWT claim name: **`pvh`**. Keep existing `challenge` UUID for now (stub); do not reuse that field. A later cleanup can drop `challenge` once `JwtChallenge` is gone.

Compact heartbeat JWT claims: `sub`, `aud`, `iss`, `exp`, `pvh`. Same RS256 keys as the fat JWT. `exp` matches the fat token’s `exp` at mint (do not extend on validate).

---

## 3. MySQL

**Whose database:** the IDP’s own schema (`phinx.php` → `$_ENV['DB_NAME']`, typically `idp`). Same server the app already migrates in `install.sh`. ORK is a **separate** product: this repo talks to it over HTTP (`OrkService`) and HS256 handoff JWTs (`IDP_ORK_SHARED_SECRET`). There is no ORK MySQL connection and no ORK table to put `pvh` in.

**How we mutate it:** a new Phinx migration in `db/migrations/`, applied with the rest of IDP migrations on the inactive slot. Persistence follows the existing Active Record pattern (`#[RepositoryOf]`, `src/Persistence/Server/Entities/` + `Repositories/`), same as `user_policy_claims` / `user_login_client`. The worker and `/resources/jwt` both use that repository; validate does not touch MySQL.

New table `user_jwt_generations` (one **current** row per user × audience):

| Column | Type | Notes |
|--------|------|--------|
| `id` | PK | |
| `user_id` | INT, not null | FK-style to `users.id` (internal) |
| `user_uuid` | CHAR(36), not null | JWT `sub`; Redis key material |
| `client_id` | INT, null | `clients.id`; null only if mint had no `aud` (should be rare) |
| `aud` | VARCHAR(255), not null | OAuth client identifier |
| `pvh` | CHAR(44), not null | time-leading hex; **index** |
| `prev_pvh` | CHAR(44), null | previous generation; 409 if presented equals this |
| `policy_hash` | BINARY(32), not null | worker equality check |
| `updated_at` | DATETIME | |

Indexes:

- **UNIQUE** `(user_uuid, aud)` — current pointer (validate/worker lookup)
- **INDEX** `pvh` — time-leading, append-friendly, optional lookup/audit
- INDEX `(user_id)`

No history table in v1. `prev_pvh` is enough for one-generation do-over. A later history table can use `pvh` as PK if we need audit.

Phinx: follow `db/migrations/20251008134043_user_policy_claims.php` style. Run in `install.sh` on the inactive slot **before** the worker image is recreated (D5 order).

---

## 4. Redis

DB 0 (`REDIS_PUBSUB_DB`), shared `amtgard-idp-sessions` in prod.

**Cache** (not the raw `userId` key used today):

```
pvh:{userUuid}:{aud}  → JSON {
  user_uuid, aud, email,
  pvh, prev_pvh
}
```

JSON, not `serialize()`. No JWT blob required on the heartbeat path. `/resources/jwt` may store the fat JWT under a separate key `jwt:{userUuid}:{aud}` **only if** userinfo needs it; default is **do not** store the bearer in Redis.

No TTL required for the pvh record (worker + remint are the writers). Logout deletes `pvh:{uuid}:*` (SCAN/pattern or maintain a set of auds per user).

**Refresh queue:** new env `REDIS_PVH_QUEUE_NAME` (default `amtgard-idp-pvh`). SetQueue key = `{userUuid}:{aud}`. Payload JSON `{"user_uuid":"...","aud":"..."}`. Duplicate validates coalesce.

**Presence queue:** keep `REDIS_PUBSUB_QUEUE_NAME` / existing `publish` on 200. Worker does **not** consume presence.

On worker boot: `redrive($queueName)` then `subscribe` + `callConsumers` loop with **exponential backoff** (D17).

---

## 5. HTTP

### `GET /resources/validate`

Auth: `Authorization: Bearer` RS256 (fat or compact). No session required (D12). Still **bind** `sub` and `aud` from the token; both required.

| Condition | Status | Enqueue | Cache write |
|-----------|--------|---------|-------------|
| Bad/missing sig, bad `iss`, expired `exp` | 401 | no | no |
| Cache hit, presented `pvh` == current | 200 `{id, email}` | **yes** | no |
| Cache hit, presented `pvh` == `prev_pvh` | 409 `{error:"stale_token"}` | no | no |
| Cache hit, presented `pvh` neither | 401 | no | no |
| Cache miss, token otherwise valid | 200, seed current=`presented pvh`, `prev_pvh`=null | **yes** | seed only |
| Fat JWT without `pvh` claim | compute `pvh` from canonical claims for compare; if cache empty, seed computed value | as above | as above |

200 **must not** include `jwt` by default. Optional `?jwt=1` (compat, documented as temporary) returns the **presented** token only, never a remint.

Do not compare full `policy` via `Policy::is()` on this path once `pvh` is in play. Keep `Jwt::validateJwt` for tests/legacy until the last fat-only caller is gone, then delete the policy-walk.

### `GET /resources/jwt`

Unchanged auth: `OAuthAccessTokenElevationMiddleware` (access token or session; **reject** authorization JWTs).

After mint: compute `policy_hash`/`pvh`, upsert `user_jwt_generations`, write Redis current (move old `pvh` to `prev_pvh` if hash changed). This is the remint well.

### `GET /resources/userinfo`

`CachedJwtLocalIdpAuthMiddleware` must not treat “user key exists” as success. Compare `pvh` with the same table as validate (409/401). **Do not** call `buildAuthorizationJwt` on every userinfo. Profile JSON only; clients that need a new snapshot call `/resources/jwt`.

### Client IAM + logout

`ClientResourcesController`: remove `invalidateUserCache` from add/delete claim and metadata. Leave DB writes as they are.

`AuthController::logout`: delete Redis `pvh:{uuid}:*` and the MySQL current row(s) for that user (or leave MySQL and only drop Redis — prefer drop Redis + set `prev_pvh` unused; next jwt recreates). Do **not** 409-loop a logged-out user: 401 because cache miss + no valid session/access token at `/resources/jwt`.

---

## 6. Worker process

**Runtime (D15):** `amtgard/redis-set-queue` already provides pub/sub + set-dedup + redrive. v1.1.2 has **no `pump()`**; the worker is a `do { $pubSub->callConsumers($queueName); /* D17 backoff */ } while (true);` loop. Upstream README `pump`/`send` names are stale.

Workerman is mentioned upstream only as an optional process host (`composer require workerman/workerman`). Alternatives in the same class: ReactPHP, Amp, RoadRunner, FrankenPHP, Swoole. This repo uses none of them. Docker Compose already keeps a long-lived process alive. Adding an event-loop framework would duplicate that, plus a new failure mode (reload vs image recreate).

Scale-out later, if needed: N replicas of the same CLI container (SetQueue still one key per `{uuid,aud}`), not Workerman inside one container.

Idle cost: after a miss streak, sleep is 100ms (~10 empty Redis dequeues/sec). After a hit, sleep is 0 so a backlog drains immediately; the next empty `callConsumers` starts 1ms, 2ms, 4ms, … 100ms. Units are milliseconds (`usleep(ms * 1000)`). Cap is 100, not 128.

`bin/jwt-pvh-worker.php`:

1. Bootstrap the same PHP-DI container as `public/index.php` (extract a shared `bootstrap.php` if needed so CLI and FPM do not diverge).
2. Connect Redis via `PubSubRedisConfig` (prod host `amtgard-idp-sessions`).
3. `redrive` + subscribe on `REDIS_PVH_QUEUE_NAME`.
4. For each `{user_uuid, aud}`:
   - Load user + client; register ORN for that client; `UserPolicy::toPolicyJson` + metadata for that login/client.
   - Compute `policy_hash`.
   - If equal to MySQL `policy_hash`, commit/ack, **no Redis write**.
   - Else rotate `pvh`/`prev_pvh`, update MySQL, write Redis, ack.
5. Library ack is not under worker control: v1.1.2 `commit()`s on success **and** after the failure handler if the subscriber throws. Log in the `subscribe` failure callback; do not fork the library. Poison-message strategy is a later concern.
6. SIGTERM: finish current job, exit 0 (Compose `stop_grace_period` ~15s).

Same image as prod app. **Command** overrides `heartbeat.sh`:

```text
php /var/www/idp.amtgard.com/bin/jwt-pvh-worker.php
```

Use **CLI** php.ini (do not inherit FPM `memory_limit = 32M` from `Dockerfile.prod` FPM pool). Worker needs headroom to build policies.

Health: process stays in the `callConsumers` loop. Optional: Redis key `pvh-worker:heartbeat` every N `callConsumers` for ops. No public HTTP port.

---

## 7. Docker and `install.sh`

### New `docker/compose.worker.yml`

Mirror `docker/compose.sessions.yml`:

- `container_name: amtgard-idp-jwt-worker`
- `image`: the **currently built prod image** (see below)
- `networks: [amtgard-idp-shared]`
- `env_file` / bind-mount `.env`, `keys/`, `dev-keys/` like `compose.prod.yml`
- `extra_hosts: host.docker.internal` for MySQL
- `restart: unless-stopped`
- `command:` PHP worker (not nginx)
- **No** membership in blue/green projects

Image tagging: today slots build untagged via `compose.prod.yml`. Implementers should tag the slot build (e.g. `amtgard-idp-app:<git-sha>`) and set the worker to that tag **after** migrate so worker code matches schema. Minimum viable: `install.sh` `compose_for_slot "$target" build` then `docker tag` that image to `amtgard-idp-jwt-worker:latest` and `compose_worker up -d`.

### `install.sh` order (mutate `install_blue_green` / `deploy_blue_green`)

1. `ensure_sessions_store` (unchanged)
2. Build + start **inactive** app slot, Phinx migrate (unchanged)
3. **`ensure_jwt_worker`**: tag image from the slot just built, `compose_worker up -d` (recreate). Worker restart is independent of nginx. `redrive` on boot recovers in-flight jobs.
4. App health check
5. Nginx switch, write active slot

Do not stop the worker when stopping a slot. `INSTALL_REBUILD_SESSIONS` must **not** be required to restart the worker; add `INSTALL_REBUILD_WORKER=1` analog if ops need a bounce.

First bootstrap: start worker after the first slot has an image (worker cannot start with no image).

### Dev

`docker/compose.dev.yml`: service `jwt-worker`, same build as `amtgardidpapp`, `depends_on` db, command CLI worker, `REDIS_PUBSUB_HOST` as today (in-container Redis from `heartbeat.sh` **or** point both app and worker at a small Redis service — prefer a dedicated `redis` service in dev so the worker does not depend on the app container’s `heartbeat.sh` redis). If that is too much for v1, run the worker **inside** the app container only in dev via `docker compose exec` documentation; prod isolation is mandatory.

---

## 8. File-level touch map

### 8.1 Must change

| File | What |
|------|------|
| `src/Controllers/Resource/LowLatencyController.php` | `pvh` compare, 200/409/401 table, enqueue refresh queue only on 200, stop recaching presented JWT as source of truth, drop session=`sub` requirement, stop returning `jwt` by default |
| `src/Persistence/Server/Repositories/RedisCacheRepository.php` | JSON keys `pvh:{uuid}:{aud}`; implement `queueUserValidation` → SetQueue `publish` on **refresh** queue; `invalidateUser` becomes delete-by-prefix for logout; **remove** serialize/unserialize |
| `src/Utility/CachedValidatedUserEntity.php` | Replace or slim to pvh record DTO |
| `src/Utility/Jwt.php` | Compact parse; `pvhFromFatClaims()`; signature/`exp`/`iss`/`aud`/`sub`; retire policy `Policy::is()` from validate path |
| `src/Models/AuthorizationJwtAssembler.php` | Add `pvh` claim; compute via shared `Pvh` helper |
| `src/Models/AmtgardIdpJwt.php` | After encode, persist generation + Redis (or assembler/service does) |
| `src/Controllers/Resource/ResourcesController.php` | `getJwt` upsert generation; `userinfo` stop remint |
| `src/Middleware/CachedJwtLocalIdpAuthMiddleware.php` | pvh check, not `isUserInCache` |
| `src/Middleware/ClientRestrictedAuthMiddleware.php` | Same cache-key/pvh semantics if it still short-circuits on Redis |
| `src/Controllers/Resource/ClientResourcesController.php` | Remove cache `DEL` on claim/metadata |
| `src/Controllers/Client/AuthController.php` | Logout: prefix delete Redis (keep) |
| `config/container.php` | Second SetQueue/handle for `REDIS_PVH_QUEUE_NAME`; `PvhGenerator` / `JwtGenerationRepository` |
| `config/routes.php` | Unchanged paths |
| `src/Utility/Redis/PubSubRedisConfig.php` | `pvhQueueName()` |
| `.env.example` | `REDIS_PVH_QUEUE_NAME` |
| `templates/api.md` | Validate 200/409; remint well; compact JWT |
| Tests listed below | |

### 8.2 New

| File | What |
|------|------|
| `src/Utility/Pvh.php` | Canonical hash + sticky `pvh` encode/decode |
| `src/Persistence/Server/Repositories/UserJwtGenerationRepository.php` | Upsert current, read by `(user_uuid, aud)` |
| `src/Services/JwtPvhRefreshService.php` | Worker body (hash DB, maybe write) |
| `bin/jwt-pvh-worker.php` | CLI loop |
| `db/migrations/YYYYMMDDHHMMSS_create_user_jwt_generations.php` | Table |
| `docker/compose.worker.yml` | Isolated worker |
| `install.sh` | `ensure_jwt_worker` |
| `tests/Utility/PvhTest.php` | Sticky timestamp, add vs revoke both change hash |
| `tests/Services/JwtPvhRefreshServiceTest.php` | no-op vs rotate |
| `tests/Controllers/LowLatencyControllerTest.php` | Rewrite for 200/409/401/enqueue |

### 8.3 Must not change (product)

| Surface | Keep |
|---------|------|
| Route set `/resources/validate`, `/resources/jwt`, `/resources/userinfo` | |
| OAuth token endpoint and refresh grant | |
| ORN strings, `policy` JSON on the **fat** JWT | Additive `pvh` claim only |
| Client IAM HTTP JSON keys | |
| Presence publish payload `(userId, email)` | Separate queue |

### 8.4 Tests that will break and must be rewritten

- `tests/Controllers/LowLatencyControllerTest.php` — miss recache, session required, returns jwt
- `tests/Persistence/RedisCacheRepositoryTest.php` — serialize, `del(userId)`
- `tests/Controllers/ClientResourcesControllerTest.php` — expects `invalidateUser` on claim/metadata
- `tests/Controllers/AuthControllerTest.php` — logout invalidate
- `tests/Middleware/CachedJwtLocalIdpAuthMiddlewareTest.php` — cache hit without pvh
- `tests/Utility/JwtTest.php` — policy compare still unit-tested until removed
- `tests/Config/ContainerPubSubRedisWiringTest.php` — second queue name

---

## 9. Failure and deploy races

| Race | Handling |
|------|----------|
| Worker crash mid-job | SetQueue redrive; at-least-once. Upsert generation must be idempotent on same `policy_hash` |
| Two workers (mistaken dual slot) | SetQueue still one key; both hashing is wasted but same result |
| App slot on old code + new worker | Single release; do not mix `DEL` app with pvh worker in production |
| Nginx cutover while worker restarts | Queue in Redis; brief delay on freshness, not on validate 200 |
| `/resources/jwt` and worker write same row | Last write wins; same `policy_hash` ⇒ same `pvh` if both respect sticky timestamp (remint should **reuse** `pvh` when hash matches, only bump `exp` on the new JWT — **new JWT, same pvh**). If remint always new `exp` but same pvh, validate still 200. Good. |

Remint with unchanged policy: new JWT bytes (`iat`/`exp`/`challenge`) but **same `pvh`**. Validate stays 200. Compact token can be reminted at `/resources/jwt` together with the fat token (same `pvh`, new `exp`).

---

## 10. Security notes (from the design thread)

- Do not put an unsigned hash in `Authorization`. Compact token is still RS256.
- Do not return a new JWT on 409.
- One-generation 409 is the do-over; unknown `pvh` is 401 (no fishing).
- Stolen fat JWT still works until `exp` **and** until the free hit is consumed; that is the accepted eventual-consistency window. Apps that authorize only from localStorage without heartbeating are unchanged (out of scope).
- Replace `unserialize` on Redis (already in the OWASP review).
