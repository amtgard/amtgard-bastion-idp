# Milestones — JWT PVH cache + worker

**Companion:** [architecture.md](./architecture.md) · [detailed-design.md](./detailed-design.md)

Implementation is a **follow-up branch**. Check items in order. Do not merge a milestone that leaves `DEL` + miss-recache in production while the worker is live.

Human merge/tag/deploy is last.

---

## M0 — Branch and goldens

- [x] Branch from current `main` — stacked as `stack/jwt-pvh-m0` off `feature/isolated-prod-blue-green` (design pack already on that base)
- [x] Record pre-change validate/userinfo/jwt fixtures: fat JWT claim set, validate 200 JSON shape, Client IAM 204-on-claim-add — `goldens/pre-change.md`
- [x] Confirm `amtgard/redis-set-queue` **1.1.2** publisher (`publish`, not `send`) and subscriber (`subscribe` / `redrive` / **`callConsumers`**, not `pump`) against `vendor/amtgard/redis-set-queue/src/PubSubQueue.php` (composer.lock pin `86ac6f37cc93d5105c7eb1a92830943a977de399`). Upstream README is stale.

**Exit:** Method names locked in a comment on `PubSubRedisConfig`. D14/D15/D17 updated. No validate/jwt/userinfo behavior change.

---

## M1 — `pvh` primitive + schema (no HTTP behavior change yet)

- [x] `Pvh` helper: sticky timestamp, `policy_hash` canonical string, hex encode/decode
- [x] Unit tests: identical policy ⇒ identical `policy_hash`; add claim ⇒ hash changes; delete claim ⇒ hash changes; metadata included; timestamp does not move on remint-with-same-hash
- [x] Phinx `user_jwt_generations` as specified
- [x] `UserJwtGenerationRepository` upsert/read
- [x] Repository tests against the test DB (or mapper mocks consistent with existing persistence tests)

**Exit:** Migration applies on dev compose; helper tests green. Validate still old behavior.

---

## M2 — Redis cache and queue plumbing (still behind old validate)

- [x] Redis keys `pvh:{uuid}:{aud}`, JSON only (no `serialize`)
- [x] `queueUserValidation` publishes to `REDIS_PVH_QUEUE_NAME` with key `{uuid}:{aud}`
- [x] Prefix delete for logout
- [x] Keep presence publish on the existing queue
- [x] Container wiring: second SetQueue + handle
- [x] `.env.example` + `PubSubRedisConfig::pvhQueueName()`
- [x] `RedisCacheRepositoryTest` / wiring tests updated

**Exit:** No production behavior change required yet if LowLatency still uses old methods; prefer to land M3 in the same PR if the old `setUser(serialize(jwt))` path would fork too hard.

---

## M3 — Validate + jwt remint + stop `DEL`

- [x] `LowLatencyController`: table in detailed-design §5 (200 / 409 / 401, enqueue on 200 only, no session requirement, no remint, no default `jwt` in body)
- [x] Fat JWT without `pvh`: compute from claims
- [x] `GET /resources/jwt`: write generation + Redis; reuse `pvh` when `policy_hash` unchanged
- [x] Assembler adds `pvh` claim on fat JWT
- [x] Remove `invalidateUserCache` from Client IAM claim/metadata paths
- [x] Logout: prefix Redis delete
- [x] Rewrite `LowLatencyControllerTest`, `ClientResourcesControllerTest` expectations, `JwtTest` as needed

**Exit:** PHPUnit green. Manual: validate 200 twice with unchanged policy does not rotate `pvh` in Redis after a worker no-op (worker may still be stubbed if M4 is next).

---

## M4 — Worker CLI + isolated container

- [ ] `JwtPvhRefreshService` + unit tests (no-op vs rotate `prev_pvh`)
- [ ] `bin/jwt-pvh-worker.php`: bootstrap, redrive, subscribe, `callConsumers` + D17 backoff, SIGTERM
- [ ] `docker/compose.worker.yml`
- [ ] `install.sh` `ensure_jwt_worker` after migrate, before nginx switch; image tag from the slot just built
- [ ] Dev: worker runnable (`compose.dev.yml` service **or** documented `exec php bin/jwt-pvh-worker.php`)
- [ ] Worker uses CLI memory limit, shared Redis DB 0, MySQL via `host.docker.internal` in prod

**Exit:** Locally: validate 200 → job coalesces → worker no-op when claims unchanged; change a claim in DB (not via `DEL`) → next worker pass rotates → following validate 409 → `/resources/jwt` with session/access token → validate 200.

---

## M5 — `userinfo` / middleware no longer remint or skip pvh

- [ ] `CachedJwtLocalIdpAuthMiddleware` (and `ClientRestrictedAuthMiddleware` if applicable) use pvh compare
- [ ] `userinfo` does not `buildAuthorizationJwt` every request
- [ ] Middleware tests rewritten
- [ ] `templates/api.md` + OpenAPI annotations: 409, remint well, compact token, validate response without `jwt`

**Exit:** Presenting only a stale authorization JWT cannot obtain a new JWT from userinfo. `/resources/jwt` still rejects authorization JWTs.

---

## M6 — Compact heartbeat (can ship after M5)

- [ ] Mint compact JWT alongside fat at `/resources/jwt` **or** document that clients derive compact from fat (`pvh` + standard claims) themselves
- [ ] Validate accepts compact-only Bearer
- [ ] Size check: compact RS256 token budget vs ~1 MTU with Host + Authorization (no session cookie) — record measured bytes in the PR
- [ ] Compat: `?jwt=1` on validate documented as temporary if used

**Exit:** Heartbeat request without session cookie fits one Ethernet frame in the measured config, or the PR explains the remaining header tax (RS256 sig ~344 chars).

---

## M7 — Proof and cleanup

- [ ] No `serialize`/`unserialize` on the pvh cache
- [ ] `queueUserValidation` no longer empty
- [ ] Grep: Client IAM paths do not `invalidateUser` / `del` for claims
- [ ] Presence publish still on 200 validate
- [ ] Infection/phpstan/cs as required by repo
- [ ] README production section: worker container next to sessions Redis

**Exit:** Ready for PR.

---

## M8 — Human merge / deploy (optional in the same week)

- [ ] Merge to `main`
- [ ] `sudo ./install.sh` — confirm sessions Redis **and** jwt-worker stay up across slot switch; worker image is the new sha
- [ ] Confirm queue drain during the worker recreate (redrive)
- [ ] Integrator note: 409 handling + `/resources/jwt` with access token; do not treat validate 401 as “please log in” until refresh grant also failed
