# ORK3 spike revision checklist

Branch: `feature/idp-send-claim-ork-code` in [amtgard/ORK3](https://github.com/amtgard/ork3).

Replace the JWT-based `SendClaimOrkCode` spike with the nonce callback design in [flow-a-ork-mail-plan.md](./flow-a-ork-mail-plan.md).

## Remove or stop using

- `system/lib/ork3/class.IdpJwtVerifier.php`
- `tests/Unit/IdpJwtVerifierTest.php`
- `IdpIntegration::SendClaimOrkCode` and JWT/`IdpToken` request fields
- `ork_idp_mailbox_challenge.idp_jti` unique replay column (optional column drop in migration revision)

## Implement

- `IdpIntegration::BeginClaimOrkMail($request)` — `SendNonce` only; synchronous IDP `validate-send-nonce` via curl + Basic auth.
- `IdpIntegration::ValidateClaimOrkCode($request)` — `IdpUserId`, `MundaneId`, `Code`; return `Email` + `MundaneId`; `password_verify` against stored hash (PHP `password_hash` / `password_verify`, no shared pepper with IDP).
- Update `docs/megiddo/idp-send-claim-ork-code.md` → rename to flow-a-ork-mail.md and document both calls.
- Mail body: code + link to `IDP_PROFILE_URL` (enter code on Amtgard Sign-In profile).

## Keep

- `ork_idp_mailbox_challenge` table shape (minus `idp_jti` if dropped)
- Rate limits (5/hour/mailbox hash, 500/hour global)
- Enum success on skip (no username / mailbox oracle)
- `IdpIntegration` registered in `orkservice/Json/index.php`

## IDP side (this repo)

Implemented behind `ORK_CLAIM_MAIL_VIA_ORK`: nonce tables, `POST /resources/ork/validate-send-nonce`, `OrkService::beginClaimOrkMail` / `validateClaimOrkCode`.

**Branch:** `feature/ork-flow-a-mail-via-ork` (stacked on `feature/operator-client-iam-fields`). `composer test` green on the stack tip.
