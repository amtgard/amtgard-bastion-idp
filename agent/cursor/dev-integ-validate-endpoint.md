# DEV integ — `/resources/validate` (LowLatency) vs Bearer on other routes

Milestone **D9** (`stack/dev-integ-d9-low-latency-validate`). Source of truth for behavior: `LowLatencyController::validate` and `config/routes.php`.

## Single HTTP route

| Item | Value |
|------|--------|
| Method / path | `GET /resources/validate` |
| Route name | `resources.validate` |
| Controller | `LowLatencyController::validate` |
| Middleware | **None** — auth is parsed inside the controller |

There is **no** `/oauth/validate` route. Older README text referred to a legacy alias; integrators should use `/resources/validate` only.

## What “Bearer validate” means in integ docs

In the route matrix and milestone tables, **“Bearer validate”** means calling `GET /resources/validate` with `Authorization: Bearer …`. That is the **same** endpoint as “LowLatency validate” — not a second route.

The milestone question is whether validate behaves like **middleware-backed Bearer routes** (e.g. `GET /resources/userinfo`). It does **not**.

## Comparison: validate vs userinfo (middleware Bearer)

| Concern | `GET /resources/validate` (LowLatency) | `GET /resources/userinfo` (middleware) |
|---------|----------------------------------------|----------------------------------------|
| Auth layer | In-controller JWT parse + issuer/`pvh` checks | `CachedJwtLocalIdpAuthMiddleware` |
| Accepts fat authorization JWT from `GET /resources/jwt` | Yes | Yes |
| Accepts `compact_jwt` from `GET /resources/jwt` | Yes | Yes |
| Accepts League **OAuth access token** as Bearer | **No** → 401 | **Yes** (via `OAuthAccessTokenFallback`) |
| PVH cache miss on valid auth JWT | **200** and **seeds** Redis (heartbeat) | **401** (does not seed) |
| Side effects on 200 | `queueUserValidation` + PubSub presence publish | None (read profile) |
| Response body | Minimal `{ "id", "email" }` (+ optional `jwt` when `?jwt=1`) | Full profile incl. optional `ork_profile` |
| Stale `pvh` | **409** `{ "error": "stale_token" }` | **409** `{ "error": "stale_token" }` |

## Integ coverage (D9)

| Test class | Scenario |
|------------|----------|
| `OAuthTokenAndResourcesTest` | Happy path: authorization JWT on validate → 200 (stack 13) |
| `LowLatencyValidateTest` | Authorization JWT → 200 + minimal JSON; `compact_jwt` → 200; OAuth access token → 401; missing Bearer → 401; access token on userinfo → 200 (contrast) |

Deferred to **D10**: fresh-token PVH / Redis path without 409 after issue (`dev-integ-coverage-plan.md`).
