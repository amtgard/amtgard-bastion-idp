# Design — OpenID Connect provider

**Companions:** [development.md](./development.md) · [checklist.md](./checklist.md)

Documents only. No application code, migrations, Composer changes, or tests land until a follow-up implementation branch.

## 1. Problem

Amtgard apps that only need a shared account cannot use a stock OpenID Connect client against this IDP. The server is OAuth 2.0 authorization-code + PKCE (`league/oauth2-server` 8.5.5). `POST /oauth/token` returns an access token and a refresh token. Identity and policy are a second hop: `GET /resources/jwt`, then `GET /resources/userinfo` with that authorization JWT.

A forum, wiki, or NextAuth-style app has to learn that dance. An OpenID Provider lets those apps verify who logged in from an `id_token` and a discovery document. ORK profile and IAM stay on the authorization JWT.

## 2. What we are building

The same login, plus an identity token when the client asks for one:

1. The relying party redirects the browser to `GET /oauth/authorize` with `response_type=code`, PKCE, `scope` including `openid`, and usually `nonce`.
2. The user signs in with the existing social login (or an existing PHP session) and approves the client, as today.
3. `POST /oauth/token` returns the access token, the refresh token, and an RS256 `id_token` when the granted scopes include `openid`.
4. The relying party verifies the `id_token` locally against `/.well-known/jwks.json`.
5. Optional: `GET` or `POST /oauth/userinfo` with the **access token** returns the same identity claims.

Apps that need ORK profile or IAM still call `GET /resources/jwt` with the access token, then `GET /resources/userinfo` with the authorization JWT.

## 3. Token layers

| Token | Proves | Used at | Stays off |
|-------|--------|---------|-----------|
| Refresh token (~1 month) | Client still has an OAuth grant | `POST /oauth/token` | Identity proof, validate |
| Access token (existing TTL) | This client may call the IDP for this user | `GET /resources/jwt`, `GET /oauth/userinfo` | Heartbeats, policy |
| ID token (same expiry as the access token) | Who authenticated, for this `aud`, at this login | Relying party's own session | `/resources/*`, IAM |
| Authorization JWT (1h, plus `pvh`) | Policy snapshot for `aud` | `validate`, `/resources/userinfo` | OIDC discovery clients |

A refresh grant that still carries `openid` returns a new `id_token` and omits `nonce`. Policy changes do not invalidate an `id_token`. They still surface as `409 stale_token` on the authorization JWT.

`sub` on the `id_token` is `users.user_id`, the same UUID as the authorization JWT `sub`.

## 4. Request classes

**OAuth client, unchanged.** `scope=profile email` returns `{access_token, refresh_token}` and no `id_token` key. `/resources/jwt` and `/resources/userinfo` stay as they are.

**OIDC relying party.** `scope=openid profile email` and a `nonce` returns those two tokens plus `id_token`. The client checks `iss`, `aud`, `exp`, `nonce`, and the RS256 signature via JWKS.

**Silent check.** `prompt=none` with no PHP session redirects back with `error=login_required`. `prompt=none` with a session but no prior approval for that client redirects back with `error=consent_required`. Neither case renders login or approve HTML.

## 5. Claims

| Claim | Scope | Source |
|-------|-------|--------|
| `sub` | `openid` | User UUID. Always on the `id_token` |
| `email` | `email` | `users.email` |
| `name` | `profile` | First and last name when either is non-empty |
| `preferred_username` | `profile` | `users.username` when non-empty |
| `updated_at` | `profile` | `users.updated_at` as a Unix timestamp when set |

Absent claims are omitted. `email_verified` and `picture` are omitted: the user row has no verified flag, and the avatar lives on a login row the token endpoint cannot attribute. `policy`, `pvh`, `orkid`, `orkuser`, and `client_metadata` stay on the authorization JWT.

`/oauth/userinfo` returns `sub` plus the scoped claims above. It does not return `aud`, `iss`, `exp`, or `nonce`.

## 6. Public endpoints

| Endpoint | Auth | Role |
|----------|------|------|
| `GET /.well-known/openid-configuration` | None | Discovery. Issuer is `APP_URL` with no trailing slash |
| `GET /.well-known/jwks.json` | None | One RSA signing key, `kid` = RFC 7638 SHA-256 thumbprint |
| `GET /oauth/authorize` | Browser session | Existing authorize, plus `nonce` and `prompt=none` |
| `POST /oauth/token` | Client credentials | Existing token response; `id_token` added when `openid` was granted |
| `GET` and `POST /oauth/userinfo` | Bearer access token that includes `openid` | OIDC UserInfo |

`response_types_supported` is `code`. `code_challenge_methods_supported` is `S256`. Production issuer is `https://idp.amtgard.com`.

`/resources/userinfo` remains the Amtgard profile endpoint. Discovery advertises `/oauth/userinfo` only.

## 7. Library

The PHP League does not publish an OpenID Provider. Use [`steverhoades/oauth2-openid-connect-server`](https://github.com/steverhoades/oauth2-openid-connect-server) **v3.0.1** as the token-response plugin on `league/oauth2-server` 8.5.5. It adds `id_token` when the access token's scopes contain `openid`, and adds nothing otherwise.

The existing JWT library stays. Signing is fine. Prove an `id_token` verifies with a unit test, an integration test, and a local login.

The plugin's default issuer is the request host. The `id_token` subclass sets `iss` from `APP_URL`. Discovery, JWKS, UserInfo, and `nonce` are IDP code. v3.0.1 last shipped on 26 September 2024, so those stay ours.

`league/oauth2-client` stays the social-login client. `firebase/php-jwt` stays the authorization-JWT signer. Relying parties are not a dependency of this repo. A PHP app can use `jumbojett/openid-connect-php` pointed at the issuer.

## 8. Out of scope

- Implicit and hybrid flows
- `prompt` values other than `none` (`login`, `consent`, `select_account` stay ignored)
- `max_age`, `acr_values`, `ui_locales`, request objects
- `at_hash`
- ORK fields on a custom OIDC scope
- Key rotation beyond a stable `kid` on the one live RSA key
- `amtgard-idp-php-client` and the examples repo
- Replacing `firebase/php-jwt` on the authorization-JWT path
- Upgrading `league/oauth2-server` to 9

## 9. Done when

1. A code-flow client configured with the issuer URL, client id, and secret can log in and read `sub` from a verified `id_token`.
2. A client that never sends `openid` still receives today's token body and can still call `/resources/jwt` and `/resources/userinfo`.
3. Discovery `issuer` and `id_token.iss` are `APP_URL`, including when the HTTP host is a different value.
4. `prompt=none` never renders the login or approve templates.
5. No ORK or policy claim appears in an `id_token` or in `/oauth/userinfo`.
