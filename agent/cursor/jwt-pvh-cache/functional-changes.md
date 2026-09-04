# Functional changes — JWT PVH cache

One entry per functional (or milestone-required documentary) change. Newest milestone at the top of each group.

---

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
