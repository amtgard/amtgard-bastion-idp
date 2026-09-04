# Functional changes — JWT PVH cache

One entry per functional (or milestone-required documentary) change. Newest milestone at the top of each group.

---

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
