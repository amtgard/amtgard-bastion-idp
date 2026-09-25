# Milestones — link by mailbox possession

**The IDP track did not ship this cutover.** Login, register, password link, and the blind mirror are still live. Possession routes sit beside them and run only when `challenge_id` is present. Follow [idp-contract.md](./idp-contract.md). Checked I3–I5 items that say those routes were deleted are historical.

**Companion:** [README.md](./README.md) · [detailed-design.md](./detailed-design.md) · [idp-contract.md](./idp-contract.md)

Check items in order inside a milestone. IDP and ORK tracks can be built in parallel after M0. They merge as one release.

## Release order

Do not enable the new claim routes in production, and do not delete the old bind, until all of the following are in the same release:

- I2, I3, I4 (IDP verifies its own mailbox, Flow B, Flow A)
- O1, O2, O3, O4 (ORK uniqueness, auto-link gone, code claim, linked email gate)

I5 (reject a blind mirror) ships in that same release. Leaving the blind mirror up after O2 is fine only on a private integration branch. It is not fine on `main`.

Human merge and deploy is last.

---

## M0 — Contract

- [ ] This pack is the contract. Implementation does not invent a second proof (email equality, ORK password, or bearer URL).
- [ ] Confirm ORK branch owner will take O1–O5. IDP work alone does not close elevation.
- [ ] Branch from current `main` in this repo. ORK branch from current ORK `master`, not from the Tobias auto-link branch. Port only the pieces listed in the design (existing-link login, handoff JWT verification, completion `jti`).

**Exit:** Both implementers are working from [detailed-design.md](./detailed-design.md) §1–§3.

---

## I1 — IDP challenge store and mail port

No HTTP behavior change.

- [x] Phinx `mailbox_challenges` as specified in the design §3
- [x] `MailboxChallengeService`: issue, resend, check, consume. HMAC with `MAILBOX_CODE_PEPPER`. 10-minute TTL, 5 attempts, 5 sends per destination per purpose per hour
- [x] Unit tests: wrong code increments attempts; sixth consumes; resend invalidates the previous hash; expired row fails; a code for another `challenge_id` fails; pepper mismatch fails
- [x] `OutboundMail`, `LogOutboundMail`, `SmtpOutboundMail` behind `MAIL_DSN`
- [x] `.env.example` documents both vars. Log transport never writes the code
- [x] Wire the service in the container. Routes call it as of I2–I5 (I1-only “no route yet” is no longer true)

**Exit:** PHPUnit for the service is green. Connect, profile link, and mirror behave as they do today.

---

## I2 — IDP email migration

Closes rewriting the IDP mailbox, which is the address Flow B trusts.

- [x] `POST /resources/profile/email/start`, `/confirm`, `/commit` under session auth and CSRF
- [x] Start mails the current `users.email`. Confirm mails `new_email`. Commit updates `users.email` only after both codes
- [x] Profile template: change-email form. Generic errors
- [x] Social callbacks (`Google`, `Discord`, `Facebook`, `Apple`) do not assign a new address onto an existing user. A differing provider email is ignored on that request
- [x] Tests: commit without the first code does not change `users.email`; commit without the second code does not; both codes do; sixth failure locks the row

**Exit:** An authenticated session cannot change the login email by posting the new address alone.

---

## I3 — Flow B (ORK session claims an IDP profile)

- [x] `OrkLinkTokenService` accepts the Flow B handoff: `sub` is mundane id, `challenge_id` and `idp_email` present, email is not used to look up or create a user
- [x] `GET /auth/connect` renders a code form. It does not link
- [x] `POST /auth/connect/code` checks the IDP challenge. Unknown address: send the code and create the user only on a successful check plus password, in that request
- [x] Known address: send the code to `users.email`. Password and social login do not consume the challenge
- [x] Success writes `linkExistingUserToMundane(..., 'ork_handoff')` and redirects with the completion JWT in the design §5
- [x] Delete the bind inside `submitConnectLogin` and `submitConnectRegister`. Routes can 404 once `/code` exists
- [x] Rewrite `ConnectControllerTest`: the nine cases in the design §10 that this controller owns (2, 4, 5, 8, and "already linked sign-in unchanged" stays outside this controller)

**Exit:** Registering at a victim's email and submitting the connect form creates no user and no `user_ork_profiles` row. Entering the code does, and only for that mundane.

---

## I4 — Flow A (IDP profile claims an ORK mundane)

- [x] Profile form posts an ORK username. IDP inserts `claim_ork` and redirects with the `iss=idp` JWT from the design §4
- [x] Completion endpoint verifies the ORK completion JWT, consumes that challenge, and calls `linkExistingUserToMundane(..., 'ork_handoff')` only when the ids match the row
- [x] `linkOrkAccount` no longer calls `OrkService::authorize`. Posting a password does not link
- [x] Tests: mismatched emails still link after the completion JWT; a completion JWT for a different `idp_user_id` than the challenge does not; password post does not

**Exit:** Design §10 cases 3 and the password half of case 2 pass on the IDP. Flow A is not live in production until O3 is deployed (ORK is what sends the code).

---

## I5 — Mirror requires a consumed challenge

- [x] `linkOrkProfile` requires `challenge_id` and returns 400 when the row is missing, unconsumed, expired, or for another user
- [x] 204 on an already stored pair (retry). 409 when either side is already linked to someone else
- [x] Update `ResourcesControllerTest` and `templates/api.md` Section 7
- [x] OpenAPI annotation on `linkOrkProfile` matches the new body

**Exit:** Design §10 case 6 passes. A confidential client cannot create the first link by naming two ids.

---

## I6 — IDP proof

- [x] `composer test` green, including the IDP-owned cases in the design §10
- [x] Grep: `ConnectController` does not call `password_verify` to decide a link. `linkOrkAccount` does not call `authorize`
- [x] Log lines for the new paths include `challenge_id` and a hash of the destination, never the code
- [ ] Manual, with `LogOutboundMail`: Flow B register, Flow B existing user, email migration, replay of the completion URL

**Exit:** IDP track is mergeable together with the ORK track. It is not mergeable alone onto a production ORK that still auto-links.

---

## O1 — One mundane, one IDP user

- [ ] Report query: `ork_idp_auth` grouped by `mundane_id` having count > 1
- [ ] Migration keeps the earliest `created_at` per mundane and writes the dropped `(mundane_id, idp_user_id)` pairs to a reviewed SQL file in the PR
- [ ] UNIQUE index on `ork_idp_auth.mundane_id`
- [ ] `EnsureIdpLink` treats a duplicate mundane as failure and does not insert

**Exit:** A second `idp_user_id` for a linked mundane cannot be stored.

---

## O2 — Remove auto-link

- [ ] Delete `tryAutoLinkByEmail` and its call from `AuthorizeIdp`
- [ ] `oauth_callback` with no `ork_idp_auth` row redirects to the claim page and writes nothing
- [ ] Test: IDP userinfo email equal to exactly one unlinked mundane does not create `ork_idp_auth` and does not log that user in
- [ ] Existing link still logs in by `idp_user_id`

**Exit:** Design §10 case 1 passes on ORK. This is the elevation blocker.

---

## O3 — Code claim in both directions

- [ ] Replace `ork_idp_claim_token` with `ork_idp_mailbox_challenge` (design §3)
- [ ] Flow A: verify the IDP JWT, mail the code to the mundane email, accept the code, then mint the completion JWT. Refuse when the mundane is already linked, suspended, or has no email
- [ ] Flow B: `start_idp_connect` mints the handoff with `challenge_id` and destination hint. `idp_link_complete` writes the row only for the session mundane and only when `mundane_id` is free
- [ ] Delete password claim (`verifyClaimCredentials` as a linker) and the 24-hour bearer link
- [ ] Tests: code mailed to the mundane completes Flow A when the IDP email is different; wrong code does not write; bearer URL from the old table is rejected; already-linked mundane sends no mail

**Exit:** Design §10 cases 3, 4, 5, and 8 pass on ORK.

---

## O4 — Linked ORK email gate

- [ ] `UpdatePlayer` on a linked mundane does not change `email` unless a consumed `migrate_ork_email` challenge names that new address
- [ ] Authorizing code goes to the current mundane email. Second code goes to the new address. Then the column updates
- [ ] Officer edit and self-edit share the gate
- [ ] Linked mundane with a blank email: `UpdatePlayer` cannot set one. `confirm_first_email` mails the linked IDP address (IDP sends it; ORK starts the request through the handoff) and stores the ORK address only after that code
- [ ] Unlinked mundane keeps the current officer email edit
- [ ] Test: park editor token plus a new email leaves a linked mundane's email unchanged (design §10 case 7)

**Exit:** A park or kingdom officer cannot point a linked profile's mailbox at themselves.

---

## O5 — ORK proof and copy

- [ ] Claim template describes a code sent to the ORK mailbox. It does not ask for the ORK password and it does not require the emails to match
- [ ] Grep: `tryAutoLinkByEmail`, `issueClaimMagicLink`, and `consumeMagicLink` are gone
- [ ] Manual with a two-account fixture: unlinked kingdom officer is not taken by an IDP user registered at the officer's email; linked officer email edit by a park PM fails; Flow A and Flow B succeed with different emails; second IDP user is rejected

**Exit:** ORK track matches I6. Release together.

---

## Follow-ups (not this release)

- Self-service unlink, using a code to the current mailbox on the side being detached
- Rate-limit dashboard beyond the per-address send cap
- What to do with the dropped pairs from the O1 report if any real user had two IDP logins
