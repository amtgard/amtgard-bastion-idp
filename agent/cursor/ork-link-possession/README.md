# ORK ↔ IDP link by mailbox possession

**Agents implementing or changing this integration:** read [idp-contract.md](./idp-contract.md). It is the IDP behavior on `feature/ork-mailbox-possession`. The design and milestone files below still describe the original cutover. Where they disagree with the contract, the contract wins.

Design pack for replacing email-equality auto-link and password-as-proof with a one-time code sent to the mailbox that owns the account being claimed.

**Scope of this pack:** documents only. No application code, migrations, or tests land until a follow-up implementation branch.

The bind crosses this IDP and ORK. IDP milestones in [milestones.md](./milestones.md) are implemented in this repo. ORK milestones are a companion checklist for `amtgard/ORK3`. Shipping one side alone leaves the elevation hole open. The release order at the top of the milestone checklist is the merge gate.

## Documents

| Doc | Purpose |
|-----|---------|
| [idp-contract.md](./idp-contract.md) | IDP behavior as built. Source of truth for agents. |
| [detailed-design.md](./detailed-design.md) | Original proof rule, flows, challenge record, file touch map |
| [milestones.md](./milestones.md) | Ordered IDP and ORK checklist. I3–I5 cutover items were not what shipped. |

## Current state

- `POST /resources/profile/link-ork` links by ORK username and password (`ResourcesController::linkOrkAccount`).
- `GET /auth/connect` plus `POST /auth/connect/login` and `POST /auth/connect/register` bind the mundane in an ORK-signed JWT to the IDP user whose email equals the JWT `email` claim (`ConnectController`, `OrkLinkTokenService`). Registration creates that user immediately. There is no mailer in this repo.
- `POST /resources/link-ork-profile` writes `{idp_user_id, mundane_id}` for the ORK confidential client. `user_ork_profiles.mundane_id` is already unique.
- ORK `feature/login-with-amtgard-workflow` (ORK3-tobias PR #19) auto-links on exact email match, accepts an ORK password as claim proof, and mails a 24-hour bearer URL. `ork_idp_auth.mundane_id` is not unique. `UpdatePlayer` still lets a park or kingdom officer rewrite a non-admin email with no message to the previous address.

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
