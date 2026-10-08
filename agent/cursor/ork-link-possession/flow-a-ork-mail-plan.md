# Flow A — ORK mails the claim code (v2)

Agent audience. Replaces the broken path where the IDP called tokenless `Player/GetPlayer` and tried to SES-mail an address ORK redacts. Trust is **high-entropy nonces and codes**, **single-use consumption**, and **rate limits** — not a shared JWT on the send call.

Companion: [ork-getplayer-email-spike.md](../ork-getplayer-email-spike.md). ORK3 spike branch `feature/idp-send-claim-ork-code` must match this doc (see [ork3-spike-revision.md](./ork3-spike-revision.md)).

## End-to-end steps

| Step | Actor | Action |
|------|--------|--------|
| 1 | User (IDP session) | Chooses ORK username on profile; IDP resolves `mundane_id` via public username search (no email). |
| 2 | IDP | Creates opaque `send_nonce`, stores `{nonce_hash, idp_user_id, mundane_id, exp}`; does **not** expose a public mint API. |
| 3–4 | IDP → ORK → IDP | **Collapsed:** IDP calls ORK `BeginClaimOrkMail(SendNonce)`. ORK **immediately** POSTs IDP `validate-send-nonce` with the nonce. IDP returns `{idp_user_id, mundane_id}` and **consumes** the nonce, or ORK aborts (no mail). |
| 4b | ORK | Loads mundane email from DB; inserts challenge row with `password_hash(code)`; SES-mails code + link to IDP profile. |
| 5 | User | Opens IDP profile, enters 16-character A–Z0–9 code (`POST /resources/profile/link-ork` with `code`, empty password). |
| 6 | IDP → ORK | IDP calls ORK `ValidateClaimOrkCode(IdpUserId, MundaneId, Code)`. On match ORK returns `{Email, MundaneId}`, consumes the challenge, and reassigns `ork_idp_auth` for that mundane to the claimer (tokens cleared). |
| 7 | IDP | Asserts returned `MundaneId` equals the **pending claim** from step 1–2; calls `linkExistingUserToMundane`. User can sign in to ORK via IDP on next login. |

After step 7, ORK may still call `POST /resources/link-ork-profile` as a mirror retry; that path stays unchanged.

## Ordering note

Steps 3 and 4 are one ORK request from the IDP’s perspective: mail must not send unless the IDP nonce callback succeeded in the same ORK transaction.

## IDP routes

| Method | Path | Auth | Caller | Behavior |
|--------|------|------|--------|----------|
| POST | `/resources/profile/link-ork-code-mail` | Session + CSRF | Browser | Steps 1–2 + ORK `BeginClaimOrkMail`. Feature flag `ORK_CLAIM_MAIL_VIA_ORK=1`. |
| POST | `/resources/profile/link-ork` | Session + CSRF | Browser | Step 5–7 when `code` present (v2 uses ORK validate, not IDP `mailbox_challenges`). |
| POST | `/resources/ork/validate-send-nonce` | Confidential client Basic (`LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`) | ORK | Step 3 callback. Body `{ "send_nonce": "<opaque>" }`. **200** `{idp_user_id, mundane_id}` or **404** `{error}`. |
| GET | `/resources/profile/link-ork/magic` | Session | Browser | Legacy **IDP-mailed** magic links only; not used when ORK sends mail. |

### Pending claim storage

Table `ork_claim_pending`: at most one open row per `idp_user_id` while waiting for the code (ties step 7 to step 1).

### Send nonce storage

Table `ork_claim_send_nonces`: single-use, ~15 minute TTL, keyed by `nonce_hash`.

## ORK JSON calls

Base: `orkservice/Json/index.php?call=IdpIntegration/...`

### `BeginClaimOrkMail`

Request:

```json
{ "SendNonce": "<opaque from IDP>" }
```

ORK:

1. POST `{APP_URL}/resources/ork/validate-send-nonce` with Basic auth (ORK confidential client).
2. On **404** / network failure → `NoAuthorization()` or hard fail (no mail).
3. On success → mundane lookup, no-mailbox / throttle checks (enum: still `Success()` to avoid username oracle). Existing `ork_idp_auth` for another IDP user does **not** block mail.
4. Store challenge; mail code; link text points to IDP profile URL (`IDP_PROFILE_URL` or configured).

### `ValidateClaimOrkCode`

Request:

```json
{
  "IdpUserId": "<uuid>",
  "MundaneId": 123,
  "Code": "AB12CD34EF56GH78"
}
```

Success payload (example):

```json
{
  "Status": { "Status": 0 },
  "Email": "player@example.com",
  "MundaneId": 123
}
```

Wrong code increments attempts; consumed row on success.

## Local Docker

See [local-docker-validation.md](./local-docker-validation.md) for `amtgard-idp-shared`, `ORK_JSON_API_BASE_URL`, and ORK3 `.dev.env`.

## Configuration

| Env (IDP) | Purpose |
|-----------|---------|
| `ORK_CLAIM_MAIL_VIA_ORK` | `1`/`true` enables v2 send + verify paths. |
| `ORK_JSON_API_BASE_URL` | Override ORK JSON endpoint (local: `http://ork3-php8-app/orkservice/Json/index.php`). |
| `APP_URL` | Browser-facing IDP base (profile links). ORK server callback uses container URL — see local doc. |
| `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS` | ORK client allowed on `validate-send-nonce`. |

| Env (ORK) | Purpose |
|-----------|---------|
| `IDP_VALIDATE_SEND_NONCE_URL` | Full URL or `{APP_URL}/resources/ork/validate-send-nonce`. |
| `IDP_LINK_CLIENT_ID` / `IDP_LINK_CLIENT_SECRET` | Basic auth for IDP server routes (same as mirror). |
| `IDP_PROFILE_URL` | Mailed link target (profile ORK link section). |
| SES / `MAIL_*` | Same as password reset. |

`IDP_ORK_SHARED_SECRET` remains for `/auth/connect` handoff JWTs; **not** required for BeginClaimOrkMail.

## What to remove / defer

- IDP `mailOrkLinkCode` → `getPlayerByMundaneId` + IDP SES for Flow A when flag is on.
- ORK spike `SendClaimOrkCode` + `IdpJwtVerifier` for send (delete or leave unused).
- `mintFlowAHandoff` redirect flow stays for the old ORK `Login/claim_ork` UI until that route is retired.

## Login after link

No new step: existing ORK IDP login uses `ork_idp_auth` populated by step 7 (and optional mirror). Elevation work in [idp-contract.md](./idp-contract.md) still applies on the ORK side.
