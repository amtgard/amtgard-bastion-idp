# IDP contract for ORK integration

Agent audience. This is what shipped on IDP `main` plus ORK PR #529 (Flow A v2). It is a migration path: legacy routes stay unless explicitly removed in a later change.

**Flow B (ORK → IDP, code to IDP email) is out of product scope.** Do not implement ORK handoffs with `challenge_id` for `/auth/connect/code`. Linking is **IDP profile → ORK mailbox code** (Flow A v2) for users who already have an IDP session. Many players have no IDP account; do not steer them through ORK into IDP registration via a second claim flow.

[detailed-design.md](./detailed-design.md) and [milestones.md](./milestones.md) describe an earlier cutover. Ignore any instruction there that removes `POST /auth/connect/login`, `POST /auth/connect/register`, `OrkService::authorize`, or a required `challenge_id` on the mirror. Ignore Flow B / I3 possession-handoff requirements unless a future ADR reopens them.

Swagger lists these routes under the **ORK Integration** tag (`GET /openapi.json`). Human copy is `templates/api.md` Section 7.

## Proof rule

The signed-in side is already authenticated. A 6-digit code proves the other mailbox. Emails do not have to match. An ORK password is not possession. An existing `(idp_user_id, mundane_id)` pair is sticky. Returning sign-in stays a lookup by `idp_user_id`.

Each system mails and checks its own mailbox for **new links**. **Shipped path:** IDP user claims ORK — ORK mails the mundane address (Flow A v2). ~~ORK user claims IDP (Flow B)~~ — **not planned.**

## Shared JWT

HS256. Secret `IDP_ORK_SHARED_SECRET`, fallback `ORK_LINK_TOKEN_SECRET`, at least 32 characters. `jti` is single-use on the IDP (`link_token_jti`).

| Token | Who mints | `iss` / `aud` | Required claims | TTL |
|---|---|---|---|---|
| Current handoff | ORK | `ork` / `idp` | numeric `sub` (mundane id), `email`, `jti`. No `challenge_id`. | ORK's current window |
| ~~Possession handoff (Flow B)~~ | — | — | **Dropped.** Do not mint. | — |
| Current completion | IDP | `idp` / `ork` | `sub` = IDP user id, `mundane_id`, `jti`. No `challenge_id`. | 300s |
| ~~Possession completion (Flow B)~~ | — | — | **Dropped.** | — |
| Flow A handoff | IDP | `idp` / `ork` | `sub` = IDP user id, `challenge_id`, `jti` | 900s |
| Flow A completion | ORK | `ork` / `idp` | `challenge_id`, `idp_user_id`, `mundane_id` > 0, `purpose=claim_ork`, `jti` | ORK's window |

`GET /auth/connect` may still parse a Flow B handoff in code; **ORK must not use that.** Prefer no new ORK links to `/auth/connect`. Legacy handoffs without `challenge_id` show login/register only where an old integration still requires it.

## Routes that today's ORK already calls

| Method | Path | Behavior |
|---|---|---|
| `GET` | `/auth/connect?link_token=` | Login/register when the JWT has `email` and no `challenge_id`. Email on the form is the JWT email. |
| `POST` | `/auth/connect/login` | `password_verify` for that email, then link and redirect to `{ORK_BASE_URL}/index.php?Route=Login/idp_link_complete&t=` with a current completion JWT. |
| `POST` | `/auth/connect/register` | Create the user at the JWT email, then the same completion redirect. |
| `POST` | `/resources/profile/link-ork` | Signed-in user. Form `username` + `password`. IDP calls `OrkService::authorize`. |
| `POST` | `/resources/link-ork-profile` | Confidential client Basic auth. Body `{idp_user_id, mundane_id}` still links. `204` / `404` / `409` unchanged. |

Browser posts send `application/x-www-form-urlencoded` and `_csrf_token`. Profile and `GET /auth/connect/complete` need the IDP session cookie.

## Possession routes (primary + legacy)

**Primary (Flow A v2, profile UI):** user already has an IDP session. ORK mails the mundane mailbox when `ORK_CLAIM_MAIL_VIA_ORK=1`.

| Method | Path | Behavior |
|---|---|---|
| `POST` | `/resources/profile/link-ork-code-mail` | Signed-in. Form `username`. When Flow A v2 is enabled, IDP calls ORK to begin mail (`BeginClaimOrkMail`); otherwise legacy IDP-local mail path. Redirect does not reveal whether the username or mailbox exists. |
| `POST` | `/resources/ork/validate-send-nonce` | ORK confidential client (Basic auth, allowlisted `client_id`). ORK validates send nonce before mailing. |
| `POST` | `/resources/profile/link-ork` | Signed-in. Form `username` + `code` (password empty). With Flow A v2: `OrkService::validateClaimOrkCode`. Otherwise local `mailbox_challenges` for `claim_ork`. Username + password still uses `authorize` (compat). |

**Flow A v1 (JWT redirect to ORK `Login/claim_ork`) — optional; profile UI uses v2 above.**

| Method | Path | Behavior |
|---|---|---|
| `POST` | `/resources/profile/link-ork-code` | Signed-in. Form `username`. Reserves `claim_ork` and redirects to `{ORK_BASE_URL}/index.php?Route=Login/claim_ork&t=&username=`. No ORK password. |
| `GET` | `/auth/connect/complete?t=` | Signed-in browser. ORK completion JWT must be `purpose=claim_ork` and the ids must match the challenge row and the session user. |
| `GET` | `/resources/profile/link-ork/magic?t=` | Legacy IDP-mailed magic link for `claim_ork`. |

**Flow B — dropped (code in repo only).**

| Method | Path | Behavior |
|---|---|---|
| `POST` | `/auth/connect/code` | **Legacy / unused.** Do not document for ORK integrators. |

When `challenge_id` is present on `POST /resources/link-ork-profile`, it must be a consumed mailbox challenge or the IDP returns `400`. Omitting the field keeps the current mirror.

## IDP email change

`POST /resources/profile/email/start` mails the current `users.email`. `confirm` checks that code and mails `new_email`. `commit` checks the second code and then writes `users.email`. Social login does not overwrite an existing user's email. `MAIL_TRANSPORT` unset or `log` means the IDP logs a destination hash and does not send mail.

`confirm_first_email` is a purpose constant only. There is no route.

## What ORK still has to ship

IDP possession routes do not close elevation. ORK still auto-links on email, accepts a password as claim proof, and lets a park or kingdom officer rewrite a non-admin email. Unlinked officer password reset stays an accepted hole. IDP IAM does not follow ORK roles.

ORK work, still unchecked:

1. Unique `ork_idp_auth.mundane_id`. Keep the earliest row. `EnsureIdpLink` must not insert a second IDP user for that mundane.
2. Delete `tryAutoLinkByEmail`. No `ork_idp_auth` row means the claim page, not a write.
3. **Flow A v2 (done in PR #529):** IDP triggers `BeginClaimOrkMail` / user enters code / `ValidateClaimOrkCode`. Optional later: JWT `Login/claim_ork` UI — not required if profile Email Code is the only entry. **Flow B:** not planned. Remove legacy password claim and 24-hour bearer link when ORK is ready.
4. A linked mundane email changes only after a code to the current address and a second code to the new one. Officer edit and self-edit share that gate. Unlinked mundanes keep today's officer email edit.
5. Claim UI asks for the code, not the ORK password, and does not require the emails to match.

Do not point production ORK at Flow B `/auth/connect` possession handoffs. IDP-first linking does not require `Login/claim_ork` unless you revive the JWT variant of Flow A.
