# Dev plan — link by mailbox possession

**Companion:** [README.md](./README.md) · [milestones.md](./milestones.md)

Implementation is a follow-up branch. Check milestones in the order in [milestones.md](./milestones.md). IDP work in this repo does not close the elevation hole until the ORK milestones in the same release remove auto-link and gate linked email writes.

## 1. Proof rule

The signed-in side is already authenticated. A 6-digit code proves the other side.

| Requester is signed in as | Account being claimed | Code is sent by | Code is sent to |
|---|---|---|---|
| An IDP profile | An ORK mundane | ORK | The email stored on that mundane |
| An ORK mundane | An IDP profile | The IDP | The email stored on that IDP profile, or the address being registered |

Emails are not required to match.

A linked email change is a migration, not a claim:

| Address being changed | Authorizing code | Second code, before the write |
|---|---|---|
| IDP `users.email` | Current IDP email | The proposed new address |
| ORK `mundane.email` | Current ORK email | The proposed new address |

An officer `UpdatePlayer`, a self-edit, and a future social-login email copy all use that gate. A provider callback that presents a different email starts a migration. It does not overwrite `users.email`.

A linked account with no email has no prior mailbox. The first address is stored only after the already-linked other side confirms: a blank ORK email requires a code to the linked IDP email, and a blank IDP email requires a code to the linked ORK email. An officer text field is not that confirmation.

## 2. What is removed

On ORK:

- `Authorization::tryAutoLinkByEmail` and the call in `AuthorizeIdp`.
- `verifyClaimCredentials` as a way to write `ork_idp_auth`.
- The 24-hour `ork_idp_claim_token` bearer URL (`issueClaimMagicLink`, `consumeMagicLink`).

On the IDP:

- `ConnectController::submitConnectLogin` and `submitConnectRegister` binding because the form password matches the user for the JWT email.
- `ResourcesController::linkOrkAccount` treating an ORK password as possession.
- `POST /resources/link-ork-profile` accepting a bare `{idp_user_id, mundane_id}` as a first-time bind.

Kept:

- `AuthorizeIdp` login when `ork_idp_auth` already has this `idp_user_id`.
- `POST /resources/profile/refresh-ork`.
- IDP 409 when the IDP user or the mundane is already linked to someone else.
- HS256 `IDP_ORK_SHARED_SECRET` for handoff and completion JWTs. The JWT carries ids. It is not a substitute for the code.

## 3. Challenge record

Same shape on both sides. Each side stores challenges for codes it sends.

| Column | Use |
|---|---|
| `id` | UUID, primary key |
| `purpose` | `claim_ork`, `claim_idp`, `migrate_idp_email`, `migrate_ork_email`, `confirm_first_email` |
| `idp_user_id` | IDP UUID, when known |
| `mundane_id` | ORK id, when known |
| `code_hash` | HMAC-SHA256 of the code with `MAILBOX_CODE_PEPPER`. The code is never stored or logged. |
| `sent_to_hash` | SHA-256 of the address actually mailed, for audit |
| `new_email` | Proposed address for a migration; null on a claim |
| `attempts` | Failed checks against this row |
| `send_count` | Resends for this destination and purpose |
| `expires_at` | 10 minutes from the latest send |
| `consumed_at` | Set when the code is accepted or the attempt cap is hit |

Rules:

- 6 digits from a CSPRNG. 5 wrong attempts consume the row. Resend replaces the hash and resets attempts. It does not extend life past 10 minutes from the new send.
- At most 5 sends per destination address per purpose per hour. Over the cap, the UI still says the code was sent.
- The row is bound to one requester, one target, and, for a migration, one new address. A code from another row does not satisfy it.
- UI and API responses do not say whether the username or email exists.
- Logs carry `challenge_id` and `sent_to_hash`. They do not carry the code or the raw address.

IDP table: `mailbox_challenges`. ORK table: `ork_idp_mailbox_challenge`, replacing `ork_idp_claim_token`.

## 4. Flow A — IDP profile claims an ORK mundane

1. Signed-in IDP user submits an ORK username on the profile page. The IDP inserts a `claim_ork` challenge with that `idp_user_id` and mints a 15-minute single-use JWT: `iss=idp`, `aud=ork`, `sub=<idp user uuid>`, `challenge_id`, `jti`, `exp`. No email claim.
2. Browser lands on ORK. ORK verifies the JWT, asks for the username (prefilled, editable), and resolves the mundane. Already linked to a different IDP user: generic failure, no mail. Suspended or penalty box: generic failure, no mail.
3. ORK generates the code, stores the hash on its challenge row bound to `(challenge_id, idp_user_id, mundane_id)`, and sends it to `mundane.email`. Blank email: generic failure.
4. The user types the code on ORK. ORK checks hash, expiry, attempts, and the binding.
5. On success ORK writes `ork_idp_auth` and redirects to the IDP with a 2-minute completion JWT: `iss=ork`, `aud=idp`, `challenge_id`, `idp_user_id`, `mundane_id`, `purpose=claim_ork`, `jti`, `exp`.
6. The IDP consumes the challenge only when `challenge_id` matches its row, `idp_user_id` is that row's user, `purpose` is `claim_ork`, and `linkExistingUserToMundane` accepts the pair. Then 302 to the profile.

`ork_idp_auth.mundane_id` becomes UNIQUE before this writer ships. A second IDP user for the same mundane fails the insert. Dedupe existing rows by keeping the earliest `created_at` and writing a report of the dropped pairs. Do not keep the newest row.

## 5. Flow B — ORK mundane claims an IDP profile

1. Signed-in ORK user submits the IDP email they want to attach. ORK mints the existing style of handoff JWT with `sub=<mundane id>`, `challenge_id` (ORK-generated), `idp_email` as a destination hint, `jti`, 15-minute expiry. `idp_email` is where to send the code. It does not select the account by string match alone and it does not authorize a bind.
2. IDP `GET /auth/connect` verifies the JWT. It does not consume `jti` yet and it does not link.
3. If an IDP user exists for that address, the code goes to `users.email`. If none exists, the code goes to the submitted address and no `users` row is created yet.
4. The user types the code on the connect page. The IDP checks its `claim_idp` row.
5. On success the IDP creates the local user if this was a registration (the password is collected on that same form, after the code), calls `linkExistingUserToMundane`, consumes the ORK handoff `jti`, and redirects to ORK with a completion JWT: `iss=idp`, `aud=ork`, `challenge_id`, `idp_user_id`, `mundane_id`, `purpose=claim_idp`.
6. ORK `idp_link_complete` writes `ork_idp_auth` only when the session mundane equals the JWT mundane and the insert is the first row for that mundane.

Registration at someone else's email string does nothing until that mailbox types the code. Google, Discord, Facebook, and Apple sign-in on the connect page do not skip the code.

## 6. Mirror

`POST /resources/link-ork-profile` stays for retries of a bind the IDP has already accepted. Body: `{idp_user_id, mundane_id, challenge_id}`.

- 204 when the pair is already stored (idempotent retry).
- 409 when either id is linked to a different partner.
- 400 when `challenge_id` is missing, unknown, expired, for another user, or not yet consumed by Flow A or Flow B.

A confidential client that knows the ORK secret cannot invent a first-time link. The challenge row is the user-started operation.

## 7. Flow C and D — email migration

IDP, new profile actions, session plus CSRF:

- `POST /resources/profile/email/start` with `new_email`. Sends the authorizing code to the current `users.email`. Inserts `migrate_idp_email`.
- `POST /resources/profile/email/confirm` with the code. On success, sends the second code to `new_email` and moves the row to "awaiting new mailbox."
- `POST /resources/profile/email/commit` with the second code. Then, and only then, update `users.email`.

ORK `UpdatePlayer` for a linked mundane ignores `Email` unless the request carries a consumed `migrate_ork_email` challenge whose `new_email` is the value being written and whose code was checked against the previous address, then against the new one. Self-edit and officer edit share this path. Unlinked mundanes keep today's officer edit. That is the accepted hole.

First address on a linked blank record uses `confirm_first_email`. The code is sent by the other system to the mailbox already on the link. ORK does not accept a typed email for that case.

## 8. IDP file touch map

| File | Change |
|---|---|
| `db/migrations/*_mailbox_challenges.php` | New table |
| `src/Services/Mailbox/OutboundMail.php` | `send(to, subject, text): void` |
| `src/Services/Mailbox/LogOutboundMail.php` | Dev default. Logs `sent_to_hash` and subject, not the code. |
| `src/Services/Mailbox/SmtpOutboundMail.php` | Used when `MAIL_DSN` is set |
| `src/Services/MailboxChallengeService.php` | Issue, resend, check, consume |
| `src/Services/OrkLinkTokenService.php` | Verify Flow A completion JWT and Flow B handoff. Ignore email as a bind key. |
| `src/Controllers/Client/ConnectController.php` | Show code form. Remove password-match bind and register-then-link. |
| `src/Controllers/Resource/ResourcesController.php` | Profile start for Flow A. Email migration actions. `linkOrkAccount` stops writing from a password. `linkOrkProfile` requires `challenge_id`. |
| `config/routes.php` | New profile email routes. Connect gains `POST /auth/connect/code`. |
| `templates/connect.twig` | Code entry. Password fields only after a registration code has succeeded, on the same submit that creates the user. |
| `templates/profile.twig` | Username field to start Flow A. Change-email form. |
| `templates/api.md` | Section 7 rewritten to this contract |
| `.env.example` | `MAILBOX_CODE_PEPPER`, `MAIL_DSN` |
| `tests/Controllers/ConnectControllerTest.php` | Replace login/register bind cases with the cases in milestone I3 |
| `tests/Controllers/ResourcesControllerTest.php` | Mirror without `challenge_id` is 400. Password post does not link. |

`RegistrationService::register` stays the user-insert helper. Connect calls it only after the code check, inside the same request that writes the link.

## 9. ORK file touch map

| File | Change |
|---|---|
| `db-migrations/*-mailbox-challenge.sql` | New challenge table. UNIQUE `mundane_id` on `ork_idp_auth` after the dedupe report. |
| `system/lib/ork3/class.Authorization.php` | Delete auto-link, password claim, and bearer magic link. `EnsureIdpLink` inserts only when `mundane_id` is free. |
| `system/lib/ork3/class.IdpHandoff.php` | Mint Flow B handoff without using email as authority. Verify Flow A JWT. Mint Flow A completion JWT only after the code checks. |
| `orkui/controller/controller.Login.php` | `oauth_callback` either logs in an existing link or sends the user to claim. `start_idp_connect` starts Flow B. `claim_submit` posts a code. Delete `claim_request_magic_link` URL tokens. |
| `system/lib/ork3/class.Player.php` | `UpdatePlayer` email write for a linked mundane requires a consumed migration challenge. |
| `orkui/template/revised-frontend/Login_claim.tpl` | Code form. Copy says which mailbox the code was sent to, without implying the emails must match. |

## 10. Tests that define done

These are the elevation and impersonation cases. A green suite that does not include them is not done.

1. Create a local IDP user at a mundane's exact email. Sign in with Amtgard. No `ork_idp_auth` row is written. The ORK session is not that mundane.
2. ORK password posted to the IDP profile, or to the claim form, does not write a link.
3. Flow A completes when the two emails differ, and only after the code mailed to the mundane is entered.
4. Flow B register at an address the requester does not read creates no `users` row and no link. After the code is entered, both exist and point at the session mundane.
5. A second completion JWT for a mundane that already has a partner returns the conflict page and leaves both tables unchanged.
6. `POST /resources/link-ork-profile` without a consumed `challenge_id` is 400, including a retry body that names a real user and a real mundane who are not yet linked.
7. `UpdatePlayer` with a new email and a park or kingdom editor token leaves `mundane.email` unchanged when the mundane is linked. The same request with a consumed migration challenge updates it.
8. Replay of a completion JWT, a sixth wrong code, and an 11-minute-old code all fail closed.
9. An already linked user signing in with Amtgard still resolves by `idp_user_id` and does not send a code.
