# IDP contract for ORK integration

Agent audience. This is what `feature/ork-mailbox-possession` actually does. It is a migration path: today's ORK keeps working, and possession routes run only when a JWT carries `challenge_id`. Do not delete the current login, register, password link, or blind mirror on the IDP.

[detailed-design.md](./detailed-design.md) and [milestones.md](./milestones.md) describe an earlier cutover. Ignore any instruction there that removes `POST /auth/connect/login`, `POST /auth/connect/register`, `OrkService::authorize`, or a required `challenge_id` on the mirror.

Swagger lists these routes under the **ORK Integration** tag (`GET /openapi.json`). Human copy is `templates/api.md` Section 7.

## Proof rule

The signed-in side is already authenticated. A 6-digit code proves the other mailbox. Emails do not have to match. An ORK password is not possession. An existing `(idp_user_id, mundane_id)` pair is sticky. Returning sign-in stays a lookup by `idp_user_id`.

Each system mails and checks its own mailbox. IDP claims ORK: ORK mails the mundane address. ORK claims IDP: the IDP mails the IDP address (or the registration hint; the user is created only after the code).

## Shared JWT

HS256. Secret `IDP_ORK_SHARED_SECRET`, fallback `ORK_LINK_TOKEN_SECRET`, at least 32 characters. `jti` is single-use on the IDP (`link_token_jti`).

| Token | Who mints | `iss` / `aud` | Required claims | TTL |
|---|---|---|---|---|
| Current handoff | ORK | `ork` / `idp` | numeric `sub` (mundane id), `email`, `jti`. No `challenge_id`. | ORK's current window |
| Possession handoff | ORK | `ork` / `idp` | numeric `sub`, `challenge_id`, `jti`, and `idp_email` (alias `email` is accepted as the hint) | ORK's window |
| Current completion | IDP | `idp` / `ork` | `sub` = IDP user id, `mundane_id`, `jti`. No `challenge_id`. | 300s |
| Possession completion (Flow B) | IDP | `idp` / `ork` | `sub`, `idp_user_id`, `mundane_id`, `challenge_id`, `purpose=claim_idp`, `jti` | 120s |
| Flow A handoff | IDP | `idp` / `ork` | `sub` = IDP user id, `challenge_id`, `jti` | 900s |
| Flow A completion | ORK | `ork` / `idp` | `challenge_id`, `idp_user_id`, `mundane_id` > 0, `purpose=claim_ork`, `jti` | ORK's window |

`GET /auth/connect` calls possession parsing first. If that returns claims, it shows the code form. Otherwise it parses the current handoff and shows login/register.

## Routes that today's ORK already calls

| Method | Path | Behavior |
|---|---|---|
| `GET` | `/auth/connect?link_token=` | Login/register when the JWT has `email` and no `challenge_id`. Email on the form is the JWT email. |
| `POST` | `/auth/connect/login` | `password_verify` for that email, then link and redirect to `{ORK_BASE_URL}/index.php?Route=Login/idp_link_complete&t=` with a current completion JWT. |
| `POST` | `/auth/connect/register` | Create the user at the JWT email, then the same completion redirect. |
| `POST` | `/resources/profile/link-ork` | Signed-in user. Form `username` + `password`. IDP calls `OrkService::authorize`. |
| `POST` | `/resources/link-ork-profile` | Confidential client Basic auth. Body `{idp_user_id, mundane_id}` still links. `204` / `404` / `409` unchanged. |

Browser posts send `application/x-www-form-urlencoded` and `_csrf_token`. Profile and `GET /auth/connect/complete` need the IDP session cookie.

## Possession routes (opt-in)

ORK opts in by minting a handoff that includes `challenge_id`. Until `Login/claim_ork` exists, nothing in the profile UI posts to the code-claim route.

| Method | Path | Behavior |
|---|---|---|
| `POST` | `/resources/profile/link-ork-code` | Signed-in. Form `username`. Reserves `claim_ork` and redirects to `{ORK_BASE_URL}/index.php?Route=Login/claim_ork&t=&username=`. No ORK password. |
| `GET` | `/auth/connect/complete?t=` | Signed-in browser. ORK completion JWT must be `purpose=claim_ork` and the ids must match the challenge row and the session user. |
| `POST` | `/auth/connect/code` | Form `link_token`, `challenge_id`, `code`. Checks the code the IDP mailed. New addresses also send `firstName`, `lastName`, `password`, `confirmPassword` on that same request. Success redirects to `Login/idp_link_complete` with a possession completion JWT. |

When `challenge_id` is present on `POST /resources/link-ork-profile`, it must be a consumed mailbox challenge or the IDP returns `400`. Omitting the field keeps the current mirror.

## IDP email change

`POST /resources/profile/email/start` mails the current `users.email`. `confirm` checks that code and mails `new_email`. `commit` checks the second code and then writes `users.email`. Social login does not overwrite an existing user's email. `MAIL_DSN` unset means the IDP logs a destination hash and does not send mail.

`confirm_first_email` is a purpose constant only. There is no route.

## What ORK still has to ship

IDP possession routes do not close elevation. ORK still auto-links on email, accepts a password as claim proof, and lets a park or kingdom officer rewrite a non-admin email. Unlinked officer password reset stays an accepted hole. IDP IAM does not follow ORK roles.

ORK work, still unchecked:

1. Unique `ork_idp_auth.mundane_id`. Keep the earliest row. `EnsureIdpLink` must not insert a second IDP user for that mundane.
2. Delete `tryAutoLinkByEmail`. No `ork_idp_auth` row means the claim page, not a write.
3. Mail a code for both directions. Flow A verifies the IDP handoff, mails the mundane address, then mints the Flow A completion JWT. Flow B mints the possession handoff (`challenge_id` + `idp_email`). Delete the password claim and the 24-hour bearer link.
4. A linked mundane email changes only after a code to the current address and a second code to the new one. Officer edit and self-edit share that gate. Unlinked mundanes keep today's officer email edit.
5. Claim UI asks for the code, not the ORK password, and does not require the emails to match.

Do not point production ORK at `Login/claim_ork` until that route exists. Do not stop sending the current handoff (`email` + `sub`, no `challenge_id`) until the possession handoff is live on both sides.
