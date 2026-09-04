# JWT PVH cache + SetQueue worker — IDP mutation plan

Design pack for replacing the Redis `DEL` stopgap with the original vision: **eventually consistent JWT validation**, a **policy-version hash (`pvh`)** on the heartbeat path, and a **background worker** that refreshes cache from MySQL via `amtgard/redis-set-queue`.

**Scope of this pack:** documents only. No application code, migrations, Compose files, or tests land until a follow-up implementation branch.

## Documents

| Doc | Purpose |
|-----|---------|
| [architecture.md](./architecture.md) | Token layers, free-hit / do-over, worker isolation from blue-green, what validate does vs `/resources/jwt` |
| [detailed-design.md](./detailed-design.md) | Locked decisions, `pvh` format, schema, Redis keys, HTTP behavior, file-level touch map, deploy wiring |
| [milestones.md](./milestones.md) | Ordered, checkable implementation milestones |

## Current state (why this pack exists)

- `GET /resources/validate` caches the presented JWT in Redis and compares policy JSON to that cache.
- Policy add/delete/metadata/logout call `RedisCacheRepository::invalidateUser()` → `DEL`.
- On the next validate, a cache miss **re-caches the stale JWT** (the opposite of revoke).
- `queueUserValidation()` is an empty stub. SetQueue is wired and used only as a **presence** publish (`userId`, `email`). There is no consumer.
- `/resources/jwt` correctly requires an OAuth access token or session and **rejects** authorization JWTs. `/resources/userinfo` remints a JWT on every success from the authorization JWT — that is the refresh-credential leak.

## Hard constraints

1. Validate is not a token endpoint. Remint only at `GET /resources/jwt`.
2. Mutation does **not** `DEL` the cache (that was the stopgap). Mutation does **not** enqueue either (that would eat the free hit).
3. Enqueue only after a **successful** validate. Worker no-ops when `policy_hash` is unchanged.
4. The worker is **not** in the blue/green app slot. It is a long-lived Compose project on the shared network, like `amtgard-idp-sessions`.
5. Integrators that still send the fat authorization JWT to validate must keep working during a compatibility window.

## Out of scope

- php-client release (document the wire so a later client pack can compact the heartbeat)
- Changing ORN / IAM evaluation
- Turning validate into a refresh-token grant
- Storing the full JWT blob in MySQL
