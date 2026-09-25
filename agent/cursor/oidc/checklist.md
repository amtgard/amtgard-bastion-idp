# Work checklist — OpenID Connect provider

Check boxes in the **implementation** PR as work lands. Design and development docs stay the source of truth; update them if a hook in v3.0.1 has moved.

**Pack:** [design](./design.md) · [development](./development.md)

Documents only until M1. Do not implement inside the doc commit.

---

## M0 — Preconditions

Landed on `stack/oidc-m0` (stacked on `feature/ork-mailbox-possession`). Design-pack commit SHA: `5fc5274e1f70b20579f81f94216f9bdc3a56dff9`.

- [x] Confirm Packagist `steverhoades/oauth2-openid-connect-server` **v3.0.1** still supports `league/oauth2-server` 8.5
  - Packagist v3.0.1 (published 2024-09-26, still the latest) requires `league/oauth2-server: ^8.4.2|^9.0`. `^8.4.2` includes 8.5.x. This repo locks `league/oauth2-server` **8.5.5**. Plugin also requires `lcobucci/jwt: 4.1.5|^4.2|^4.3|^5.0`; current lock is `lcobucci/jwt` **5.6.0**. Keep that JWT lock. Do not change `composer.lock` JWT packages. Signing stays on the plugin.
- [x] After install, confirm `IdTokenResponse` still lets a subclass set the issuer and still adds `id_token` only when the `openid` scope is present
  - Confirmed in installed `vendor/steverhoades/oauth2-openid-connect-server/src/IdTokenResponse.php` (lock **v3.0.1**). `getBuilder()` is still `protected` and still sets `issuedBy('https://' . $_SERVER['HTTP_HOST'])`, so a subclass can override `iss`. `getExtraParams()` still returns `[]` unless `isOpenIDRequest()` finds a scope identifier `openid`, and only then adds the `id_token` key. Constructor third argument is still `?string $keyIdentifier` and still writes the `kid` header when set. Hooks have not moved. No development.md update. Response type is not wired in M2.
- [x] Re-read `ClaimExtractor::extract` and confirm a claim is omitted only when its key is absent (`null` values are copied)
  - Confirmed against v3.0.1 `ClaimExtractor::extract` (GitHub tag `v3.0.1`). It intersects the claim-set keys with `array_keys($claims)`, then `array_filter(..., ARRAY_FILTER_USE_KEY)`. Values are not filtered; a present key with `null` is copied. A claim is omitted only when its key is absent from the claims array.
- [x] Confirm `OAuthUser` identifiers are `users.user_id` (`UserRepository::getUserEntityById`)
  - `UserRepository::getUserEntityById` looks up via `findUserByUserId` → `fetchBy('user_id', ...)`. `OAuthUser` is built with `identifier($user->getUserId())`. `UserEntity::$userId` is `#[Field('user_id')]`. `UserRepositoryTest::testGetUserEntityByIdWrapsUserInOAuthEntity` asserts the identifier is that UUID.
- [x] Confirm production `APP_URL` is `https://idp.amtgard.com` with no path and no trailing slash
  - `AuthorizationJwtAssembler::ISSUER` is `https://idp.amtgard.com`. Root README and `templates/api.md` use the same origin. No path, no trailing slash. `.env.example` stays local `http://localhost:37080`.

**Exit:** pin agreed; Packagist constraint still matches 8.5. Plugin hook points for `IdTokenResponse` are deferred to M2 (after install). Claim omission, `sub` identifier, and production issuer confirmed.

---

## M1 — Branch cut

Landed on `stack/oidc-m1` (stacked on `stack/oidc-m0` / `feature/ork-mailbox-possession`). Bookkeeping commit SHA: `5c0583b67e0f0b85cfe5127610b696e418994c44`.

**Stack replacement (recorded on `stack/oidc-m0`, confirmed on `stack/oidc-m1`):** Implementation uses stacked branches `stack/oidc-m0` through `stack/oidc-m10` on top of `feature/ork-mailbox-possession`. That stack replaces the single `feature/oidc-provider` branch from `main`. Do not create `feature/oidc-provider` from `main`. No draft PR was opened.

- [x] ~~Create branch `feature/oidc-provider` from current `main`~~ **Superseded.** The stacked branches `stack/oidc-m0` through `stack/oidc-m10` on `feature/ork-mailbox-possession` replace the single `feature/oidc-provider`-from-`main` branch. Created `stack/oidc-m1` from `stack/oidc-m0` (`f7d5e03e29c7a886ee2bb3fdfc8a3906f695045b`). Did not create `feature/oidc-provider`. Did not branch from `main`.
- [x] ~~Open a draft PR that links `agent/cursor/oidc/` and copies [design §9](./design.md#9-done-when) as the acceptance list~~ **Superseded.** No PR was opened. There is no PR URL.
- [x] Leave sibling repos alone (`amtgard-idp-php-client`, examples)
  - Confirmed untouched. No files changed outside this repo.

**Exit:** checklist records the stack replacement and that no PR was opened. No draft PR URL.

**Adapted M1 is checklist bookkeeping only.** No production code, Composer, or migration changes. Implementation starts at M2.

---

## M2 — Dependency and scope seed

Landed on `stack/oidc-m2` (stacked on `stack/oidc-m1`). Implementation commit SHA: `aa7d06d74ec94f4188f1c8e506cc9c93279d8d7a`.

- [x] `composer require steverhoades/oauth2-openid-connect-server:^3.0`
- [x] Lock resolves to **v3.0.1**
  - `composer.lock` pins `steverhoades/oauth2-openid-connect-server` **v3.0.1**. `league/oauth2-server` stays **8.5.5**. `lcobucci/jwt` stays **5.6.0**. Signing stays on the plugin; no custom `id_token` signer.
- [x] Phinx migration:
  - [x] `auth_codes.nonce` string(255) null
  - [x] insert `scopes.scope_id = 'openid'` if missing
  - [x] `down` drops the column and deletes that scope row
- [x] `ScopeRepository::$VALID_SCOPES` includes `openid`, `email`, `profile`
- [x] Extend `tests/Persistence/ScopeRepositoryTest.php` for the new allow list

**Exit:** `openid` survives `finalizeScopes()` and exists in `scopes`. Token JSON is still unchanged because the response type is not wired yet.

---

## M3 — Identity claims on `OAuthUser`

Landed on `stack/oidc-m3` (stacked on `stack/oidc-m2`). Implementation commit SHA: `430640976bf7525628a6de1fbd3926258c70cef4`.

- [x] `OidcClaimFactory` maps `UserEntity` per [design §5](./design.md#5-claims)
  - `OidcClaimFactory::fromUser` emits `sub`, `email`, `name`, `preferred_username`, and `updated_at` (Unix timestamp). Empty, null, and whitespace-only values omit the key. `name` is first and last when either is non-empty.
- [x] `OAuthUser` implements `OpenIDConnectServer\Entities\ClaimSetInterface`
  - `getClaims()` returns `OidcClaimFactory::fromUser($this->userEntity)`.
- [x] Unit test: empty names omit `name`; missing email omits `email`; no `email_verified`, `picture`, `orkid`, or `policy` key
  - `tests/Models/Oidc/OidcClaimFactoryTest.php` and `tests/Persistence/OAuthUserTest.php`.

**Exit:** claim array is identity only.

---

## M4 — `id_token` on the token response

Landed on `stack/oidc-m4` (stacked on `stack/oidc-m3`). Implementation commit SHA: `5c89c53436537415915df3ab6f200142a942817e`.

- [x] `OidcIdTokenResponse` sets the issuer from `APP_URL`. Leave signing to the plugin
  - `getBuilder()` uses `rtrim(APP_URL, '/')`. Plugin still signs with `OAUTH_PRIVATE_KEY` / RS256.
- [x] `kid` from `JwksFactory` is passed into the parent constructor
  - RFC 7638 SHA-256 thumbprint of the public key. One RSA JWK. Signing stays on the plugin.
- [x] `IdentityRepository` adapts `UserRepository::getUserEntityById`
- [x] Default `profile` and `email` claim sets stay. No custom claim set
  - `new ClaimExtractor()` only. No `addClaimSet('profile')`.
- [x] `OAuthServerConfiguration::build()` passes the response type as the sixth `AuthorizationServer` argument
- [x] `OidcAuthCodeGrant` only calls `parent` in this milestone (nonce is M5)
- [x] Unit test: an `id_token` verifies (`iss`, `aud`, `sub`, expiry matches the access token)
  - `tests/Models/Oidc/OidcIdTokenResponseTest.php`
- [x] Integration test: `POST /oauth/token` with `openid` returns an `id_token` that verifies; without `openid` there is no `id_token`
  - `tests/Controllers/OidcTokenEndpointTest.php`
- [x] `iss` is `APP_URL` when the request host is different

**Exit:** code exchange issues an identity token. Clients that omit `openid` see the same JSON keys as before.

---

## M5 — Nonce

Landed on `stack/oidc-m5` (stacked on `stack/oidc-m4`). Implementation commit SHA: `c11b6fe8d488bc8ddb313907c332c8bb432da054`.

- [x] Session store saves `nonce` (1–255 chars; longer → `invalid_request`)
- [x] `buildPostAuthenticationRedirectUrl()` copies `nonce` back onto `/oauth/authorize`
- [x] `persistNewAuthCode()` writes `auth_codes.nonce` and clears the session key
- [x] `OidcAuthCodeGrant` decrypts `code`, loads nonce by `auth_code_id`, stashes `OidcNonceContext`, then calls `parent`
- [x] `OidcIdTokenResponse` adds the `nonce` claim and clears the context. Context is not cleared before `generateHttpResponse()`
- [x] Refresh grant path does not set the context
- [x] Tests: login-redirect URL keeps nonce; code-exchange `id_token` echoes it; refresh `id_token` has no `nonce` claim

**Exit:** a relying party can require `nonce` on the first `id_token` and will not see it after refresh.

---

## M6 — `prompt=none`

Landed on `stack/oidc-m6` (stacked on `stack/oidc-m5`). Implementation commit SHA: `8e08fbd92d7621f9d81d1346fc82af2b25b061f8`.

- [x] Store `prompt` the same way as `nonce`, including across `buildPostAuthenticationRedirectUrl()`
- [x] `prompt=none` plus any other prompt value → `invalid_request`
- [x] `prompt=none` and no session → redirect to `redirect_uri` with `error=login_required` and `state`. The browser does not go to `/auth/login`
- [x] `prompt=none`, session present, no prior client approval and no `approved` session flag → `error=consent_required`. The approve template is not rendered
- [x] Any other `prompt` value is ignored; login and consent behave as they do today
- [x] Tests: both error redirects, and `prompt=login` while logged out still reaches `/auth/login`

**Exit:** silent clients get OIDC errors instead of HTML.

---

## M7 — Discovery and JWKS

Landed on `stack/oidc-m7` (stacked on `stack/oidc-m6`). Implementation commit SHA: `2ba44c6cc2ff40b49b1f8bf74c6f9c93bc2e2b8c`.

- [x] `JwksFactory` builds one RSA JWK (`n`, `e`, `use=sig`, `alg=RS256`, thumbprint `kid`)
- [x] `GET /.well-known/jwks.json` returns that document and no private parameters
- [x] `GET /.well-known/openid-configuration` matches [development §5](./development.md#5-http-details), with URLs based on `APP_URL`
- [x] Both routes are public `GET`s with `Cache-Control: public, max-age=3600`
- [x] Test: `id_token` header `kid` equals the JWKS `kid`, and a verify with the JWK modulus succeeds

**Exit:** a client can bootstrap from the issuer URL alone.

---

## M8 — OIDC UserInfo

Landed on `stack/oidc-m8` (stacked on `stack/oidc-m7`). Implementation commit SHA: `695d48911d717d0136cb89219c676fecdb739a03`.

- [x] `GET` and `POST /oauth/userinfo`
- [x] Validate the bearer access token with the existing `ResourceServer`
- [x] Missing `openid` → `403` and `WWW-Authenticate: Bearer error="insufficient_scope"`
- [x] Body is `sub` plus scoped claims from `OidcClaimFactory`. `Cache-Control: no-store`
- [x] `POST` accepts form `access_token` when the header is absent; header wins when both are present
- [x] An authorization JWT is `401`
- [x] `ResourcesController::userinfo` and its OpenAPI contract stay as they are
- [x] Tests: access-token success, missing `openid`, authorization-JWT rejection

**Exit:** discovery `userinfo_endpoint` works with the access token. `/resources/userinfo` still requires the authorization JWT.

---

## M9 — Integrator docs

Landed on `stack/oidc-m9` (stacked on `stack/oidc-m8`). Implementation commit SHA: `869841b7f2f8ad2148e41655dc0cf33714e354e1`.

- [x] `templates/api.md`: authorize params `openid` and `nonce`, token `id_token`, discovery, `/oauth/userinfo`. Keep the `/resources/jwt` flow for ORK and IAM
- [x] Root `README.md`: this IDP is an OAuth 2.0 authorization server and an OpenID Provider for the code flow
- [x] Do not add `jumbojett/openid-connect-php` to this repo

**Exit:** an integrator can choose OIDC or the existing resource flow from the docs.

---

## M10 — Acceptance pass

Landed on `stack/oidc-m10` (stacked on `stack/oidc-m9`). Implementation commit SHA: `4f25dc23e861f0ec2a38cdc108a479766cf4789e`.

**No PR was opened.** The stacked branches replace `feature/oidc-provider`. Acceptance is recorded here, not on an implementation PR.

Local stack was already up (`amtgard-idp` on port 37080, bind-mounted to this tree). Applied the pending M2 Phinx migration `20260925120000_openid_scope_and_auth_code_nonce` so `scopes` includes `openid` and `auth_codes.nonce` exists. `APP_URL=http://localhost:37080`. Confidential client used for live authorize: `test_client` (`clients.id=8`, `is_confidential=1`, `redirect_uri=http://localhost:37080`). Full interactive social login was not completed unattended; `/auth/login` is the Google/Facebook/Discord/Apple page. No live code was redeemed. No secrets were committed.

- [x] Walk [design §9](./design.md#9-done-when) on a running local stack (`./scripts/dev-up.sh`, port 37080)
  - Live curl: discovery, JWKS, `prompt=none` authorize, contrast authorize→login, unauthenticated `/resources/jwt` and `/oauth/userinfo`.
  - Suite: code-flow `id_token`, nonce, host≠`APP_URL` issuer, UserInfo vs resources profile, token without `openid`, `prompt=none` HTML-never, claim omission. See design §9 notes below.
- [x] Code-flow login with `scope=openid profile email` and a `nonce`, using a confidential client already in the local `clients` table
  - Live: `GET /oauth/authorize` with `test_client`, `scope=openid profile email`, `nonce=m10-live-nonce`, PKCE S256 is accepted. No session → 301 `/auth/login` (login HTML). `prompt=none` → 302 `redirect_uri?error=login_required&state=…`, never `/auth/login`. Did not finish social login or redeem a code.
  - Suite: `OidcTokenEndpointTest` + `OidcTokenExchangeHarness` (confidential `oidc-test-client`) exchanges `openid profile email` with a nonce and returns a verifiable `id_token`.
- [x] Verify the `id_token` with the local JWKS (`iss` is `APP_URL`, `sub` is the user UUID, `nonce` matches)
  - Live: `GET http://localhost:37080/.well-known/jwks.json` → 200, `Cache-Control: public, max-age=3600`, one RSA JWK (`kty/use/alg/kid/n/e`), no private parameters. No live `id_token` to verify against that JWKS.
  - Suite: `OidcDiscoveryJwksVerifyTest` verifies the code-flow `id_token` against the JWKS document (`kid` match + `JWK::parseKeySet`). `OidcTokenEndpointTest::testCodeExchangeIdTokenEchoesNonce` (`nonce=rp-nonce-1`). `iss` is `rtrim(APP_URL,'/')`; `sub` is `users.user_id` UUID `11111111-1111-1111-1111-111111111111`.
- [x] `/oauth/userinfo` with the access token and `/resources/userinfo` with an authorization JWT from `/resources/jwt` both succeed, each with its own body
  - Live: unauthenticated `GET /oauth/userinfo` → 401 `{"error":"invalid_token"}`; unauthenticated `GET /resources/jwt` → 401 (OAuth access token or session required). Did not call either with a minted token.
  - Suite: `OidcUserInfoEndpointTest` — access token with `openid email` returns `{sub, email}` (`Cache-Control: no-store`); `openid profile email` adds `name` and `preferred_username` and omits `aud`/`iss`/`exp`/`nonce`. Authorization JWT on `/oauth/userinfo` is 401. `ResourcesControllerTest` — `/resources/jwt` returns `{jwt, compact_jwt}`; `/resources/userinfo` returns `{id, email}` plus optional `ork_profile`.
- [x] Token exchange without `openid` has no `id_token`
  - Suite: `OidcTokenEndpointTest::testPostOauthTokenWithoutOpenidOmitsIdToken` — keys are exactly `token_type`, `expires_in`, `access_token`, `refresh_token`. No live token exchange.
- [x] `./scripts/dev-up.sh test` (or `composer test` in the app container) is green
  - `docker exec amtgard-idp bash -lc "cd /var/www/idp.amtgard.com && XDEBUG_MODE=off composer test"` → PHPUnit 12.5.14, **795 tests, 3324 assertions, OK**. Existing warnings/deprecations/notices/1 skipped. No production PHP changed on this branch; coverage/infection of new production lines is N/A.

**Design §9 (verified):**

1. Code-flow client can log in and read `sub` from a verified `id_token`. **Suite** (`OidcTokenEndpointTest`, `OidcDiscoveryJwksVerifyTest`). Live authorize with `test_client` only reached login / `login_required`.
2. Client that never sends `openid` still gets today’s token body and can still call `/resources/jwt` and `/resources/userinfo`. **Suite** (`OidcTokenEndpointTest` without `openid`; `ResourcesControllerTest` jwt + userinfo). Not live-exchanged.
3. Discovery `issuer` and `id_token.iss` are `APP_URL`, including when the HTTP host differs. **Live** discovery issuer is `http://localhost:37080` with `Host: evil.example` as well. **Suite** `OidcTokenEndpointTest::testIdTokenIssuerIsAppUrlWhenRequestHostDiffers` (`HTTP_HOST=evil.example`, `iss=https://idp.amtgard.com`).
4. `prompt=none` never renders the login or approve templates. **Live** no-session `prompt=none` → 302 `login_required`, empty body, not `/auth/login`. Contrast without `prompt` → `/auth/login` (title `Login - Amtgard Identity Provider`). **Suite** `OAuthAuthorizePromptTest`: `login_required` and `consent_required` with `view->render` never called.
5. No ORK or policy claim in an `id_token` or `/oauth/userinfo`. **Suite** `OidcClaimFactoryTest::testClaimsOmitAuthorizationAndUnverifiedKeys` (no `orkid`/`policy`/`pvh`/`orkuser`/`client_metadata`); UserInfo body is scoped identity claims only. Resources userinfo remains the ORK profile shape.

**Exit:** acceptance is checked on this checklist on `stack/oidc-m10`. No implementation PR was opened.
