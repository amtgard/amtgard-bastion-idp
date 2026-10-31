# ORK ↔ IDP link by mailbox possession

**Flow A (ORK mails the claim code, nonce callback):** [flow-a-ork-mail-plan.md](./flow-a-ork-mail-plan.md).

**Product decision (2026-10):** **ORK linking always originates on the IDP.** The user must create an IDP account or sign in first, then start linking from `/resources/profile` via **Email Code** (Flow A v2 when `ORK_CLAIM_MAIL_VIA_ORK=1`). **Flow B is dropped:** we will not build ORK-initiated “go to the IDP and prove your IDP inbox” linking. Most players do not have IDP accounts yet; that path adds confusion. ORK “Log in with Amtgard” stays for **already linked** accounts only. Legacy ORK `/auth/connect` login/register handoffs remain in code for old integrations but are **compat-only—not the planned UX.**

**Status:** IDP milestones **I1–I6 are on `main`**. ORK checklist **O1–O5** in [milestones.md](./milestones.md) is still open (`amtgard/ORK3`). Human API copy: `templates/api.md` §7.

**Agents:** read [idp-contract.md](./idp-contract.md) first. [detailed-design.md](./detailed-design.md) and older milestone cutover text are historical. Where they disagree with the contract, the contract wins.

Design pack for mailbox possession linking (codes to the mailbox that owns the account being claimed), replacing email-equality auto-link as the **planned** UX. Legacy password and `/auth/connect` handoff routes remain for compatibility until ORK and product remove them.

## Documents

| Doc | Purpose |
|-----|---------|
| [idp-contract.md](./idp-contract.md) | IDP behavior as built. Source of truth for agents. |
| [detailed-design.md](./detailed-design.md) | **Historical** — pre-ship cutover; Flow B table rows are not product |
| [milestones.md](./milestones.md) | IDP done checklist + open ORK work; I3 Flow B **cancelled** |

## Shipped on IDP (`main`)

- **Planned UX:** signed-in user on `/resources/profile` → **Email Code** → `POST /resources/profile/link-ork-code-mail`; with `ORK_CLAIM_MAIL_VIA_ORK=1`, ORK mails the mundane mailbox and validates via `POST /resources/ork/validate-send-nonce` (see [flow-a-ork-mail-plan.md](./flow-a-ork-mail-plan.md)).
- **Compat:** `POST /resources/profile/link-ork` with username + password still calls `OrkService::authorize`. `GET /auth/connect` login/register handoff (email in JWT, no possession). Flow B `/auth/connect/code` exists in code but is **not** product.
- **Mirror:** `POST /resources/link-ork-profile` for the ORK confidential client; optional `challenge_id` enforcement when present.
- **Email migration:** `POST /resources/profile/email/{start,confirm,commit}`.

ORK-side elevation (auto-link, password claim, officer email rewrite) is **not** fixed by IDP alone; see milestones **O1–O5**.

## Hard constraints

1. A link is proof that the requester typed a code sent to the mailbox of the account being claimed. Equal email strings are not proof. An ORK password is not proof.
2. The system that stores the mailbox sends the code and checks it. ORK checks the ORK address. The IDP checks the IDP address.
3. An existing `(idp_user_id, mundane_id)` pair is sticky. A claim code does not move it.
4. A linked account changes its email only after a code to the address already stored is entered. The new address also accepts a code before it becomes the address of record.
5. Returning sign-in for an already linked pair stays a lookup by `idp_user_id`. This pack does not add a code to every login.

## Out of scope

- Park or kingdom officers setting the password on an **unlinked** mundane. That ORK session hole stays.
- Creating a new ORK player from the IDP.
- A self-service unlink screen. Any later unlink uses the same code-to-current-mailbox rule.
- Copying ORK admin authority onto IDP IAM claims. The two stores stay separate.
- IDP `email_verified` as an OIDC claim. Possession is the challenge row, not a boolean on `users`.
