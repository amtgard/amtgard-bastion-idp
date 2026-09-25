# Development — OpenID Connect provider

**Companions:** [design.md](./design.md) · [checklist.md](./checklist.md)

How to implement the design on the current tree. Decisions below are locked; the checklist is the order to land them.

## 1. Locked decisions

| ID | Decision |
|----|----------|
| D1 | Require `steverhoades/oauth2-openid-connect-server:^3.0` and lock **v3.0.1**. Stay on `league/oauth2-server` 8.5.5 |
| D2 | `OidcIdTokenResponse` is the sixth `AuthorizationServer` constructor argument. Clients without `openid` get no `id_token` key |
| D3 | `iss` is `rtrim(APP_URL, '/')`. Ignore the plugin's `HTTP_HOST` issuer |
| D4 | `sub` is the existing `OAuthUser` identifier (`users.user_id`) |
| D5 | Sign with `OAUTH_PRIVATE_KEY`, RS256, `kid` = RFC 7638 SHA-256 thumbprint of the public key. One JWK in v1 |
| D6 | `id_token.exp` equals the access-token expiry |
| D7 | Claims are `openid` / `profile` / `email` only. See the design claim table |
| D8 | `getClaims()` omits empty keys. `ClaimExtractor` in v3.0.1 copies a key that is present, including `null` |
| D9 | Leave the plugin's default `profile` and `email` claim sets alone. `addClaimSet('profile')` throws; those scopes are protected |
| D10 | `nonce` is stored on `auth_codes` and copied into the authorization-code `id_token` only. Refresh `id_token`s omit it |
| D11 | UserInfo is `GET` and `POST /oauth/userinfo` with a bearer access token that includes `openid` |
| D12 | Discovery and JWKS are public `GET`s at `/.well-known/openid-configuration` and `/.well-known/jwks.json` |
| D13 | Honor `prompt=none` only. `none` plus any other prompt value is `invalid_request`. Other prompt values are ignored |
| D14 | `openid` is added to `ScopeRepository::$VALID_SCOPES` and to the `scopes` table. `finalizeScopes()` drops anything else, so a miss in either place means no `id_token` |
| D15 | Keep the current JWT library. The plugin signs the `id_token`. A unit test and an integration test verify that token |
| D16 | `firebase/php-jwt` keeps signing authorization JWTs |

## 2. Plugin integration

`OAuthServerConfiguration::build()` today constructs `AuthorizationServer` with five arguments, so League uses `BearerTokenResponse`. Pass `OidcIdTokenResponse` as the sixth.

`IdTokenResponse::getExtraParams()` (v3.0.1) emits `id_token` only when some scope identifier is `openid`. `getBuilder()` is `protected` and sets `issuedBy('https://' . $_SERVER['HTTP_HOST'])`. The constructor's third argument, `?string $keyIdentifier`, writes the `kid` header. Re-read those three points in `vendor/.../IdTokenResponse.php` immediately after `composer require`. If they moved, stop and update this doc before wiring.

`OAuthTokenAction` stays a pass-through to `respondToAccessTokenRequest()`.

## 3. Signing

Use the plugin's signer and the existing private key. Publish the matching public key at `/.well-known/jwks.json`, with a `kid` on both the key and the `id_token`. The private key stays out of JWKS.

Prove it with a unit test that verifies an `id_token`, an integration test that does the same through `POST /oauth/token`, and a local login.

## 4. Nonce

League's `AuthorizationRequest` has no nonce field, and `AuthCodeGrant::validateAuthorizationCode()` is private. Do not paste a copy of `respondToAccessTokenRequest`. Hook the public edges. On `league/oauth2-server` 8.5.5, `decrypt()` and `getRequestParameter()` are `protected` on `AbstractGrant`, so a subclass can use them.

1. **Authorize (browser).** On the first `GET /oauth/authorize`, if `nonce` is present, require a string of 1–255 characters and store it beside `authRequest`. Longer values are `invalid_request`. `buildPostAuthenticationRedirectUrl()` must append that `nonce` when it rebuilds `/oauth/authorize` after login. Today that rebuild lists a fixed set of query keys and drops `nonce`.
2. **Persist.** `AuthCodeRepository::persistNewAuthCode()` copies the session nonce onto `auth_codes.nonce` and clears the session key. `completeAuthorizationRequest()` still runs in the browser, so the session is present.
3. **Redeem (back channel).** `OidcAuthCodeGrant::respondToAccessTokenRequest` decrypts `code`, reads `auth_code_id`, loads `nonce` into a request-scoped `OidcNonceContext`, then calls `parent::`. Do not clear the context in a `finally`. `getExtraParams()` runs later, inside `generateHttpResponse()`. The response subclass adds the `nonce` claim and then clears the context.
4. **Refresh.** `RefreshTokenGrant` never sets the context. Scopes live in League's encrypted refresh token, so `openid` still produces an `id_token`, without `nonce`.

`prompt` uses the same session slot and the same rebuild of the authorize URL. `prompt=none` is handled in `OAuthAuthorizeAction` before the redirect to `/auth/login` and before the approve redirect.

## 5. HTTP details

Discovery `Cache-Control: public, max-age=3600`. URLs are the issuer plus the paths in the design. Document shape:

```json
{
  "issuer": "https://idp.amtgard.com",
  "authorization_endpoint": "https://idp.amtgard.com/oauth/authorize",
  "token_endpoint": "https://idp.amtgard.com/oauth/token",
  "userinfo_endpoint": "https://idp.amtgard.com/oauth/userinfo",
  "jwks_uri": "https://idp.amtgard.com/.well-known/jwks.json",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "subject_types_supported": ["public"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "scopes_supported": ["openid", "profile", "email"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post", "none"],
  "code_challenge_methods_supported": ["S256"],
  "claims_supported": ["sub", "iss", "aud", "exp", "iat", "nonce", "email", "name", "preferred_username", "updated_at"]
}
```

Token success when `openid` was granted adds `id_token` next to the existing `access_token`, `refresh_token`, `expires_in`, and `token_type`. `expires_in` stays `OAUTH_ACCESS_TOKEN_TTL`. Without `openid`, the JSON keys are unchanged.

UserInfo:

- `ResourceServer` validates the bearer access token.
- Missing `openid` → `403` and `WWW-Authenticate: Bearer error="insufficient_scope"`.
- An authorization JWT → `401`.
- `Cache-Control: no-store`.
- `POST` may read form field `access_token` when the header is absent. When both are present, use the header.
- Do not change `ResourcesController::userinfo` or its OpenAPI contract.

## 6. Schema

Reversible Phinx migration:

```text
auth_codes.nonce   string, limit 255, null
scopes              insert scope_id = 'openid' if missing
```

`down` drops the column and deletes that scope row. No new table. Do not log the nonce.

`ScopeRepository::$VALID_SCOPES` becomes `['openid', 'email', 'profile']`.

## 7. Files

| File | Change |
|------|--------|
| `composer.json` / `composer.lock` | `steverhoades/oauth2-openid-connect-server:^3.0` locked at v3.0.1 |
| `src/Models/OAuthServerConfiguration.php` | Build `OidcIdTokenResponse` and `OidcAuthCodeGrant`; pass the response type in |
| `src/Models/Oidc/OidcIdTokenResponse.php` | `iss` from `APP_URL`, `nonce` from the context. Signing stays on the plugin |
| `src/Models/Oidc/OidcAuthCodeGrant.php` | Stash nonce, then `parent::respondToAccessTokenRequest` |
| `src/Models/Oidc/OidcNonceContext.php` | Request-scoped string holder |
| `src/Models/Oidc/OidcClaimFactory.php` | `UserEntity` → claim array, empty keys omitted |
| `src/Models/Oidc/IdentityRepository.php` | `IdentityProviderInterface` over `UserRepository::getUserEntityById` |
| `src/Persistence/Server/Entities/OAuth/OAuthUser.php` | `ClaimSetInterface` |
| `src/Persistence/Server/Repositories/AuthCodeRepository.php` | Persist and fetch nonce |
| `src/Persistence/Server/Repositories/ScopeRepository.php` | Allow `openid` |
| `src/Controllers/Server/OAuth/OAuthAuthorizeAction.php` | Capture `nonce` and `prompt`; keep them across the login redirect; `prompt=none` errors |
| `src/Controllers/Server/OAuth/OAuthSessionAuthRequestStore.php` | Session keys for nonce and prompt |
| `src/Controllers/Server/OAuth/DiscoveryController.php` | Discovery and JWKS |
| `src/Controllers/Server/OAuth/OidcUserInfoController.php` | UserInfo |
| `src/Utility/JwksFactory.php` | PEM → JWK and thumbprint `kid` |
| `config/routes.php` | The three new routes |
| `config/container.php` | Wire the response type, grant, and nonce context |
| `db/migrations/*_openid_scope_and_auth_code_nonce.php` | Section 6 |
| `templates/api.md`, `README.md` | OIDC section. `/resources/userinfo` stays the authorization-JWT profile |
| `tests/fixtures/oidc/` | Fixture RSA key, not a production key |
| `tests/` | Section 8 |

## 8. Tests

Unit test: build an `id_token` and verify it (`iss`, `aud`, `sub`, `nonce`, `exp` equals the access-token expiry, `kid` matches JWKS).

Integration test: `POST /oauth/token` with `openid` returns an `id_token` that verifies the same way. Without `openid`, the body has no `id_token` key.

Also:

- Refresh with `openid` → `id_token` and no `nonce` claim.
- `HTTP_HOST` different from `APP_URL` → `iss` is `APP_URL`.
- Discovery uses `APP_URL`. JWKS `n` and `e` match the fixture public key and include no private parameters.
- `/oauth/userinfo` with the access token returns `sub` and `email` when `email` was granted, and `403` when `openid` was not.
- `/oauth/userinfo` with an authorization JWT returns `401`.
- `prompt=none` without a session redirects with `login_required` and does not go to `/auth/login`.
- `buildPostAuthenticationRedirectUrl()` still carries `nonce`.
- `finalizeScopes()` still drops unknown scopes and still keeps `email` and `profile`.
- Claim factory: empty names omit `name`; missing email omits `email`; no `email_verified`, `picture`, `orkid`, or `policy`.

## 9. Local acceptance

After `./scripts/dev-up.sh` (app on port 37080):

1. Code-flow login with `scope=openid profile email` and a `nonce`, using a confidential client already in the local `clients` table.
2. Verify the `id_token` against `http://localhost:37080/.well-known/jwks.json`. `iss` is `APP_URL`, `sub` is the user UUID, `nonce` matches.
3. `GET /oauth/userinfo` with the access token, and `GET /resources/userinfo` with an authorization JWT from `GET /resources/jwt`. Each returns its own shape.
4. Token exchange without `openid` has no `id_token`.
5. `./scripts/dev-up.sh test` is green.

PHP relying-party examples stay out of this repo. Point `jumbojett/openid-connect-php` at the issuer if an example app needs a client later.
