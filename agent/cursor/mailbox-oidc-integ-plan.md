# Mailbox + OIDC — DEV_INTEG plan

HTTP integration for mailbox possession and OpenID Provider routes on `ENVIRONMENT=DEV_INTEG`.

## Mail boundary

| Live | Integ |
|------|--------|
| `config/container/mailbox.php` → `OutboundMailFactory` / `LogOutboundMail` (hashed recipient only) | `config/container/mailbox.integ.php` → `IntegRecordingOutboundMail` |

`EnvironmentLoader` registers `mailbox.php` with integ file `mailbox.integ.php` (same pattern as auth-providers / ork).

Recordings land in pub/sub Redis (`REDIS_PUBSUB_*`) under `integ:mailbox:delivery:{sha256(email)}` as JSON `{code, subject, text}`. Tests read via `tests/Integration/Support/IntegRecordedMail.php` (docker exec into `amtgard-idp-sessions-integ`). **Never** assert mailbox codes from `LogOutboundMail` hashes.

`docker/compose.integ.yml` sets `MAILBOX_CODE_PEPPER` on the web container; `scripts/integ-up.sh` forwards it into the php-fpm pool (same as `IDP_ORK_SHARED_SECRET`).

## ORK fake extensions

`DevIntegHttpClient` serves `SearchService/Player` for username search and returns `INTEG_ORK_MAILBOX_EMAIL` on `Player/GetPlayer` so Flow A mail + magic link integ can run without live ORK.

## Test classes

| Class | Routes / behavior |
|-------|-------------------|
| `OidcDiscoveryIntegTest` | `GET /.well-known/openid-configuration`, `GET /.well-known/jwks.json`, OAuth code + `openid` → `id_token` (JWKS verify, `nonce`), `GET`/`POST /oauth/userinfo` |
| `MailboxPossessionIntegTest` | `GET /auth/connect` + `POST /auth/connect/code`, `POST /resources/profile/link-ork-code-mail`, `GET /resources/profile/link-ork/magic`, `POST /resources/profile/link-ork-code`, `GET /auth/connect/complete` |

Fixtures: `IntegFixtures::mintPossessionConnectLinkToken`, `mintFlowACompletionToken`.

## Gates

```bash
composer test
./scripts/integ.sh
```

Restart integ stack after changing `mailbox.integ.php` or compose overlay so the web container picks up `ENVIRONMENT=DEV_INTEG` and `MAILBOX_CODE_PEPPER`.

## Matrix

Update [dev-integ-route-matrix.md](./dev-integ-route-matrix.md) when adding cases.
