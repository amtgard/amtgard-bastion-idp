# Architecture — JWT PVH cache and isolated worker

**Companion:** [detailed-design.md](./detailed-design.md) · [milestones.md](./milestones.md)

## 1. What we are building

A heartbeat that is cheap and eventually consistent:

1. Client presents a signed JWT (fat today; compact+`pvh` preferred).
2. Validate serves from Redis. On **match**, return 200 and enqueue an idempotent “refresh from source” job.
3. A **worker container** (not the web slot) consumes that job, hashes current policy in MySQL, and updates Redis **only if** the content hash changed.
4. The next heartbeat with the old `pvh` is **one generation behind** → 409, not logout. Client remints at `/resources/jwt` with an access token (or session), which writes cache, then retries validate.

That is the original SetQueue vision. `DEL` on mutation was a stopgap and goes away.

## 2. Token layers (do not collapse)

| Token | Proves | Used at | Not used for |
|-------|--------|---------|--------------|
| Refresh token (~1 month) | Client still has an OAuth grant | `POST /oauth/token` | Policy mutation, validate |
| Access token (~1–2h) | Short OAuth session | `GET /resources/jwt` | Heartbeats |
| Authorization JWT (1h, plus `pvh`) | Policy snapshot for `aud` | `validate`, `userinfo` | Minting a new JWT |

Policy change is not an OAuth event. Spend a refresh token only when the **access token** is dead. Login only when the refresh token is dead.

Validate **must not** return a reminted JWT. Possession of a stale authorization JWT is enough for the free hit, not enough to issue a new one (stolen Bearer would keep extending `exp` and picking up current policy without the access token).

## 3. Two request classes

### Common — valid

```
GET /resources/validate   (signed JWT, pvh matches Redis current)
  → 200 {id, email}         // no fat jwt in the body
  → enqueue SetQueue key = {sub, aud}
  → worker: hash DB policy; if same policy_hash, write nothing
```

### Uncommon — stale (one generation behind)

```
GET /resources/validate   (pvh === prev_pvh)
  → 409 {error: "stale_token"}
  → do not enqueue
  → client: GET /resources/jwt with access token or session
           (if access token expired: refresh grant, then jwt)
  → remint writes MySQL generation row + Redis
  → retry validate
```

Unknown `pvh`, bad signature, expired JWT, `sub`/`aud` mismatch → **401**. No enqueue. Not a do-over.

Cold cache (empty Redis, token otherwise valid) → treat as a **free hit**: seed Redis from the presented `pvh`, 200, enqueue. The worker may then discover a newer hash and the *next* call 409s.

## 4. Why mutation does not touch the queue

The free hit is “cache still has the old `pvh` until a successful validate enqueues the worker.”

| Action on policy add/revoke | Effect |
|-----------------------------|--------|
| `DEL` (today) | Miss recaches the stale token; revoke fails |
| Enqueue on mutation | Worker may refresh **before** the next validate; no free hit |
| Neither (this plan) | Next successful validate is the free hit; worker runs after |

`/resources/jwt` still writes cache immediately. That is a remint, not a heartbeat.

## 5. Worker isolation (like Redis)

Blue/green **app** slots (`amtgard-idp-blue` / `amtgard-idp-green`) are replaced on deploy. `amtgard-idp-sessions` is **not**: it holds PHP sessions (DB 1) and SetQueue/cache (DB 0) so cutover does not drop queue work or log people out.

The PVH worker must follow the **sessions** pattern, not the app-slot pattern:

- Own Compose project, e.g. `amtgard-idp-worker`, container `amtgard-idp-jwt-worker`.
- `restart: unless-stopped`, attached to `amtgard-idp-shared`.
- Same prod **image** as the app (code + `vendor/`), but **CLI entrypoint** — no nginx/php-fpm. Queue state lives in Redis, not in the worker process.
- During `install.sh`: Redis stays up → migrate on the **inactive** app slot → recreate worker from the new image → health-check app → nginx switch. The worker restart is seconds; Redis retains unacked SetQueue entries (`redrive` on boot).
- Do **not** run the worker inside `heartbeat.sh` of a web slot. That dies with the inactive slot and duplicates if both slots run.

Dev: a `jwt-worker` service on `compose.dev.yml` with the same bind mount as the app, command `php bin/jwt-pvh-worker.php`.

## 6. `pvh` is a generation id, not “hash of now”

```
policy_hash = SHA-256(aud || "\n" || Policy::toJson() || "\n" || canonical client_metadata)
pvh         = time_prefix (48-bit unix ms, when THIS generation was born)
              || truncated policy_hash (16 bytes)
```

Same claims ⇒ same `policy_hash` ⇒ worker **reuses** the existing `pvh` (timestamp stays). New claims ⇒ new timestamp prefix + new hash.

A live clock inside the hash would make every worker pass look stale and destroy the common-case no-op.

Validate compares `pvh` (or computes it from a fat JWT’s policy). It does not tell add from revoke. Both are “generation changed.” Entitlement is in the reminted fat JWT.

## 7. Compatibility

- **Accept** fat authorization JWTs on validate (compute `pvh` from claims if `pvh` is absent).
- **Prefer** compact JWT: `sub`, `aud`, `iss`, `exp`, `pvh` (no `policy` / `client_metadata` / identity clutter). RS256 signature remains.
- **Mint** `pvh` as an added claim on the fat JWT from `/resources/jwt` (additive; old clients ignore unknown claims).
- 200 from validate **stops echoing** the fat JWT (MTU). Compatibility query or header if a known integrator still needs it, then remove.

`userinfo` stops reminting on every call. It returns identity/profile; JWT refresh is `/resources/jwt` only. If presented `pvh` is one generation behind, 409 same as validate.
