# Integration tests (`ENVIRONMENT=DEV_INTEG`)

Stacked git-branchless milestones. Each branch stacks on the previous one. One commit per milestone after that milestone’s checks pass. Do not start the next branch with a dirty tree.

**Unit vs integ:** `composer test` stays the default, fast PHPUnit run (sqlite, no live app). It never includes `tests/Integration/`. **`composer integ`** runs only `phpunit.integ.xml` (HTTP against `:37080`). Integ is opt-in and expected to be slower.

Base for the stack: current **`main`**.

## What this mode is

Reuse Docker’s existing **`ENVIRONMENT`** knob (today `DEV` / `PROD` in Compose; not read by PHP yet). Integ adds **`ENVIRONMENT=DEV_INTEG`**.

| `ENVIRONMENT` | Where | App behavior |
|---|---|---|
| `PROD` | Production compose | Real outbound HTTP |
| `DEV` | `./scripts/dev-up.sh` | Real outbound HTTP; schema `idp` |
| `DEV_INTEG` | Integ overlay only | Fake OAuth/ORK HTTP via container includes; schema `idp_integ` |

`config/container.php` branches after Dotenv loads: when `($_ENV['ENVIRONMENT'] ?? '') === 'DEV_INTEG'`, load `auth-providers.integ.php` and `ork.integ.php`. Do **not** put `ENVIRONMENT=DEV_INTEG` in committed `.env` / `.env.example`. The integ Compose overlay (or `integ-up.sh`) sets it on the **web container** only.

PHPUnit runs in the host (or test container); the app under test runs in **amtgard-idp**. Flipping env on the PHPUnit CLI does not change php-fpm until the app container is restarted with the overlay.

The running php-fpm process loads the real container, real routes, real middleware, real MariaDB, and real Redis. Curl talks to `http://localhost:37080`. That is the container under test. In-process `$app->handle()` is not this suite.

PHPUnit mocks cannot live inside php-fpm. The replacements are plain PHP fakes constructed by the integ container includes.

## Classes that stay real

These are not mocked. Their container includes are always the production includes.

| Include | Definitions |
|---|---|
| `config/container/persistence.php` | `Database`, `DataAccessPolicy`, `UncachedDataAccessPolicy`, `RepositoryPolicy`, `EntityManager`, every repository key currently in `config/container.php` |
| `config/container/sessions-redis.php` | `RedisDataStructureConfig`, `Redis`, `PubSubQueueHandle`, `SetQueue`, `PvhSetQueue`, `PvhQueueHandle`, `PubSubQueue`, `PvhAuthorizationGate` |
| `config/container/oauth-server.php` | `OAuthServerConfiguration`, `AuthorizationServer`, `ResourceServer`, `OAuthFlowErrorRenderer`, `OAuthTokenAction`, `OAuthApproveAction`, `OAuthAuthorizeAction`, `AmtgardIdpJwt` |
| `config/container/resources.php` | `ResourcesUserinfoService`, `ClientIamPolicyService`, `ClientIamMetadataService` |
| composition root `config/container.php` | `LoggerInterface`, `TwigEnvironment`, `AuthorizedClients`, `CurrentUserResolverInterface`, `ManagementMiddleware`, and the `require` list below |

Also real, because they are not third-party HTTP: session (`SessionMiddleware` is constructed in `config/middleware.php`, not the container), CSRF, `OrkLinkTokenService` (local HS256 plus `link_token_jti`), management-key compare, local PEM JWT signing. Autowired controllers and middleware stay autowired.

`POST /oauth/authorize` returns an empty 200. Do not test it.

Mailbox/SMTP has no route and no container key. Do not invent a mail include until those classes exist.

## Container includes swapped in integ mode

Only outbound HTTP that cannot be called in this suite.

| Production include | Integ include | What stays real | What the integ include swaps |
|---|---|---|---|
| `config/container/auth-providers.php` | `config/container/auth-providers.integ.php` | `League\OAuth2\Client\Provider\Google`, `Facebook`, `Apple`, `Wohali\OAuth2\Client\Provider\Discord` | The Guzzle client passed as League `collaborators['httpClient']` |
| `config/container/ork.php` | `config/container/ork.integ.php` | `OrkService` | The Guzzle client `OrkService` uses for `https://ork.amtgard.com/orkservice/Json/index.php` |

Today those clients are not injectable. League `AbstractProvider` does `new GuzzleHttp\Client` when `collaborators['httpClient']` is omitted (`config/container.php` around the four provider factories). `OrkService::__construct` does `new Client` itself (`src/Services/OrkService.php`). Milestones 2 and 3 move that construction into the container without changing runtime behavior. Milestone 4 is the first time `ENVIRONMENT=DEV_INTEG` selects the integ includes.

`config/container.php` becomes the composition root:

```php
$integ = ($_ENV['ENVIRONMENT'] ?? '') === 'DEV_INTEG';

return array_merge(
    require __DIR__ . '/container/persistence.php',
    require __DIR__ . '/container/sessions-redis.php',
    require __DIR__ . '/container/oauth-server.php',
    require __DIR__ . '/container/resources.php',
    require __DIR__ . '/container/' . ($integ ? 'auth-providers.integ.php' : 'auth-providers.php'),
    require __DIR__ . '/container/' . ($integ ? 'ork.integ.php' : 'ork.php'),
    [ /* logger, twig, authorized clients, current user, management */ ],
);
```

`config/bootstrap.php` already calls Dotenv before `addDefinitions`. No second container builder. Later definitions must not be required to override earlier ones; the branch picks one file.

The integ HTTP fake is one class, `config/container/integ/DevIntegHttpClient.php`, implementing `GuzzleHttp\ClientInterface` (or a Guzzle `HandlerStack` the real `Client` wraps). It dispatches on the request URL host and path and returns canned JSON. It does not use PHPUnit. It logs the host it answered at info, with no tokens and no passwords.

Canned responses the fake must serve:

- Google token + userinfo (`email`, `sub`, name). Email `integ-google@example.com`.
- Facebook token, long-lived exchange, Graph user. Email `integ-facebook@example.com`.
- Discord token + user. Email `integ-discord@example.com`.
- Apple `https://appleid.apple.com/auth/keys` (a JWKS that matches the fixture signing key) and `https://appleid.apple.com/auth/token`. Email comes from the callback form, which the test posts.
- ORK `Json/index.php`: `authorize` success for username `integ-ork`, and `getPlayer` / park lookup for a fixed mundane id.

A `code` of `integ-deny` on a social token request returns HTTP 400 so a callback test can assert the app’s error redirect. Everything else returns the canned user for that host.

Apple stays off unless `APPLE_LOGIN_ENABLED=true`. The integ overlay turns it on and sets `APPLE_KEY_FILE_PATH` to the key already referenced by `phpunit.xml`: `vendor/code-rhapsodie/oauth2-apple/test/src/private_key.pem`. The fake serves JWKS. The real Apple provider still signs the client secret.

## How the suite exercises the app

Two modes, both HTTP, both against the overlay. Cookie name is PHP’s `PHPSESSID`. CSRF field is `_csrf_token`. Header form is `X-CSRF-Token`. A cookie-jar test must not send `Authorization: Bearer`; `LocalIdpAuthMiddleware` returns 401 when that header is present.

### Mode A — curl cookie jar, UI URLs

Tooling: `curl --cookie-jar` / `--cookie`. wget with `--save-cookies` / `--load-cookies` is the same contract; the committed runner uses curl.

1. `GET /auth/login` and store the jar. Session starts. Parse `_csrf_token` out of the HTML (it is in the page even when the email form is collapsed).
2. `POST` `application/x-www-form-urlencoded` with `_csrf_token` plus the form fields. Follow redirects with the same jar (`-L` only after the cookie is stored; prefer `--max-redirs` and assert the first status, then the final URL).
3. Later HTML POSTs (approve, profile, management) `GET` the form first and parse a fresh token. Do not reuse the login token.

Login fields (`templates/login_form.twig`): `_csrf_token`, `email`, `password`.

Register (`templates/register_form.twig`): `_csrf_token`, `firstName`, `lastName`, `email`, `password`, `confirmPassword`.

Approve (`templates/oauth_approve.twig`): `_csrf_token`, `callback`, `action` (`allow` or `deny`).

Connect login: `_csrf_token`, `link_token`, `email`, `password`.

Social login does **not** follow the provider redirect. `GET /auth/google` (and Facebook, Discord, Apple) returns 302 to the vendor. The test keeps the jar, discards that `Location`, and requests the callback itself with `code=integ-ok` and the `state` the app stored in the session. The app then calls the provider, the fake HTTP client answers, and the app 302s into the IDP. Apple’s callback is `POST` form_post, not GET.

### Mode B — direct API, no cookie

No CSRF. Auth is HTTP Basic (`client_id:client_secret`, plaintext compare in `ClientRepository::validateClient`), Bearer (access token or authorization JWT, depending on the route), or `?key=` for `GET /management/cleantokens`.

| Call | Auth | Success |
|---|---|---|
| `GET /version`, `GET /openapi.json` | none | 200 |
| `POST /api/is_authorized` | none | 200 `is_authorized` |
| `POST /oauth/token` | Basic or body client credentials | 200 `access_token` |
| `GET /resources/jwt` | session **or** OAuth access token | 200 |
| `GET /resources/userinfo` | authorization JWT or access token | 200 |
| `GET /resources/validate` | Bearer JWT | 200 |
| `GET /resources/authorizations` | Bearer when the caller is not the IDP session client | 200 |
| Client IAM under `/resources/client/*` | Basic + confidential + `iam_service` (service-format GET is Basic only) | 204 or 200 as documented in `templates/api.md` |
| `POST /resources/link-ork-profile`, `POST /resources/unlink-ork-profile` | Basic + `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS` | 204 |
| `GET /management/cleantokens?key=` | `MANAGEMENT_KEY` length ≥ 32 | 200 text |

Mode B token calls that need an authorization code reuse the Mode A approve flow in the same test, then exchange the code with no cookie.

## Local commands (milestone 5)

Composable pieces (use when debugging one step):

| Command | Role |
|---|---|
| `./scripts/integ-up.sh` | Apply integ compose overlay: `ENVIRONMENT=DEV_INTEG`, create/migrate `idp_integ`, seed fixtures, restart **amtgard-idp** |
| `composer integ` | `vendor/bin/phpunit -c phpunit.integ.xml` only — no stack changes |
| `./scripts/integ-down.sh` | Restore normal dev: `ENVIRONMENT=DEV`, `DB_NAME=idp`, drop overlay |

**Convenience wrapper** (same milestone):

```bash
./scripts/integ.sh          # integ-up → composer integ → integ-down (always tear down)
./scripts/integ.sh --keep   # integ-up → composer integ; leave stack in DEV_INTEG (optional flag name TBD)
```

`integ.sh` exits non-zero if any step fails; on failure it still runs `integ-down` unless `--keep` was passed (so a broken run does not strand the dev app in integ mode). Document in README next to `./scripts/dev-up.sh`.

**PHPUnit wiring**

- `phpunit.xml`: exclude `tests/Integration` from the default suite so `composer test` never runs integ.
- `phpunit.integ.xml`: `<testsuite>` = `tests/Integration` only; env `IDP_BASE_URL=http://localhost:37080` (override via shell if needed). Does **not** use sqlite — tests talk HTTP only.
- `composer.json`: `"integ": "phpunit -c phpunit.integ.xml"` (exact invocation TBD for Docker vs host PHP 8.4).

If `/version` is down or the app is still on `ENVIRONMENT=DEV`, integ tests **fail** (no `markTestSkipped`).

## Harness shape (details)

- `docker/compose.integ.yml` overlay on the dev stack. Sets `ENVIRONMENT=DEV_INTEG`, `DB_NAME=idp_integ`, `APPLE_LOGIN_ENABLED=true`, the vendor Apple test key path, `SESSION_REDIS_PREFIX=INTEGSESS:`, and a `MANAGEMENT_KEY` of at least 32 characters. Does not point at the developer schema `idp`.
- `tests/Integration/seed.php`, invoked from `integ-up.sh` (not from every bare `composer integ`), truncates fixture rows and inserts:
  - user `integ-player@example.com` with a known password
  - user `integ-admin@example.com` with the same password and policy claim `Idp:0::::IDP/EditClient` (admin UI)
  - confidential client `integ_confidential` / secret `integ-confidential-secret`, `iam_service` set, redirect `http://localhost:37080/integ/callback`, allowed for ORK link via `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`
  - scopes `email` and `profile` if the seed migration has not already inserted them
- Tests may mint an ORK connect JWT with `IDP_ORK_SHARED_SECRET` (`iss=ork`, `aud=idp`). That path stays real.

`ContainerResolutionOrderTest` keeps booting `config/bootstrap.php` with `ENVIRONMENT=DEV` (or unset) and still resolves the real provider classes. Add one unit test that sets `ENVIRONMENT=DEV_INTEG` in `$_ENV` before `require bootstrap.php`, boots a container, and asserts the social HTTP client is the integ fake. That test does not replace the curl suite.

**Dotenv note:** bootstrap uses `Dotenv::createMutable`. Prefer **`createImmutable`** (or equivalent) so Compose / shell `ENVIRONMENT=…` wins over `.env` when both are present. Integ docs assume `ENVIRONMENT` is not set in the committed `.env` file.

## Milestones

| # | Branch | Phase | Proves |
|---|---|---|---|
| 1 | `stack/dev-integ-1-container-includes` | Split | App unchanged; container is includes |
| 2 | `stack/dev-integ-2-social-http-seam` | Seam | Real League providers take an injected HTTP client |
| 3 | `stack/dev-integ-3-ork-http-seam` | Seam | Real `OrkService` takes an injected HTTP client |
| 4 | `stack/dev-integ-4-mode-switch` | Mode | `ENVIRONMENT=DEV_INTEG` loads integ includes only |
| 5 | `stack/dev-integ-5-harness` | Harness | `integ-up` / `integ-down` / `integ.sh`, `composer integ`; curl reaches overlay |
| 6 | `stack/dev-integ-6-ui-session` | Mode A | Register, login, profile, logout |
| 7 | `stack/dev-integ-7-oauth-approve` | Mode A | Authorize, allow, deny |
| 8 | `stack/dev-integ-8-social-callbacks` | Mode A | Google, Facebook, Discord callbacks |
| 9 | `stack/dev-integ-9-apple-callback` | Mode A | Apple form_post callback |
| 10 | `stack/dev-integ-10-ork-and-profile` | Mode A | Connect JWT, link, refresh, unlink, revoke |
| 11 | `stack/dev-integ-11-management-ui` | Mode A | Admin clients, redirect, user search, access grant |
| 12 | `stack/dev-integ-12-public-api` | Mode B | version, openapi, is_authorized, cleantokens |
| 13 | `stack/dev-integ-13-oauth-and-resources` | Mode B | token, refresh, jwt, userinfo, validate, authorizations |
| 14 | `stack/dev-integ-14-client-iam` | Mode B | client IAM and ORK profile mirror |

### 1. `stack/dev-integ-1-container-includes`

Move every definition array out of `config/container.php` into the includes listed above. `auth-providers.php` and `ork.php` are the production factories copied as they are today (Guzzle still constructed inside League and inside `OrkService`).

Introduce **`EnvironmentLoader`** (see `~/.cursor/skills/php-slim-architecture/SKILL.md`): composition root registers each module and `require`s `$envLoad->emit(...)`. Integ filenames may be registered as `null` until milestone 4. Do not branch on `ENVIRONMENT` for alternate includes yet.

Done when `composer test` passes and `GET /version` on the normal dev app still returns 200. No behavior change. Container resolution order test still resolves `Google`, `OrkService`, and `ClientIamPolicyService` from the composed container.

**Completed (milestone 1):** Branch `stack/dev-integ-1-container-includes`. Split `config/container.php` into `config/container/{persistence,sessions-redis,oauth-server,resources,auth-providers,ork}.php`; composition root uses `EnvironmentLoader` (`src/Utility/Config/EnvironmentLoader.php`) with all integ include args `null`. Unit tests: `tests/Utility/Config/EnvironmentLoaderTest.php` (include selection + debug log on `emit`). Gates: `composer test` OK (616 tests); Infection on `EnvironmentLoader` covered MSI 87%. Whole-tree line coverage unchanged (pre-existing gate). No `ENVIRONMENT=DEV_INTEG` branching yet.

### 2. `stack/dev-integ-2-social-http-seam`

`auth-providers.php` builds one real Guzzle client and passes it as `collaborators['httpClient']` into all four providers. Default client is the same timeout and redirect behavior League uses when it constructs its own. Add a container key the providers close over, for example `SocialOAuthHttpClient`.

Done when social unit tests still pass with mocked provider objects, and a focused test shows the object stored on the provider is that client. No integ overlay yet. Callbacks against the normal dev app still try the real vendors; do not call them.

### 3. `stack/dev-integ-3-ork-http-seam`

`OrkService` takes `GuzzleHttp\ClientInterface` from the container instead of `new Client`. `ork.php` supplies the real client, including the User-Agent and Referer headers and `verify => false`. Update `tests/Services/OrkServiceTest.php` so it injects the fake client through the constructor instead of reflection on `tempClient`.

Done when `OrkService` unit tests and Infection on `src/Services/OrkService.php` meet the project gates (`--min-msi=80 --min-covered-msi=80` for that filter). Log lines that already exist stay. Do not add a log on the constructor.

### 4. `stack/dev-integ-4-mode-switch`

Add `config/container/integ/DevIntegHttpClient.php` and the two `*.integ.php` includes. `container.php` selects them only when `ENVIRONMENT=DEV_INTEG`. Production includes still construct the real clients.

The integ social include still `new`s the four League providers. The integ ORK include still `new`s `OrkService`. Both receive `DevIntegHttpClient`.

Done when a process with `ENVIRONMENT=DEV_INTEG` resolves `Google` as the real League class and its HTTP client as `DevIntegHttpClient`, and `ENVIRONMENT=DEV` resolves a real Guzzle client. `composer test` stays green. Unit-test the fake’s URL dispatch: Google host returns the canned user, `integ-deny` returns 400, ORK host returns the canned player, an unknown host throws.

### 5. `stack/dev-integ-5-harness`

Add the overlay, `scripts/integ-up.sh`, `scripts/integ-down.sh`, `scripts/integ.sh`, seed script, exclude `tests/Integration` from default `phpunit.xml`, add `phpunit.integ.xml`, and `composer integ`.

First tests, Mode A unless noted:

- Mode B `GET /version` is 200 JSON.
- Mode A `GET /auth/login` stores `PHPSESSID` and the body contains `_csrf_token`.

Done when `./scripts/integ.sh` fails if Docker is down, and succeeds end-to-end on a healthy dev stack. `./scripts/integ-down.sh` leaves `ENVIRONMENT=DEV` and real Guzzle on the app container.

### 6. `stack/dev-integ-6-ui-session`

Mode A, one jar:

- `POST /auth/register` for a fresh email → 302 `/resources/profile` → `GET` profile 200.
- `GET /auth/logout` → 302 `/`.
- `POST /auth/login` as `integ-player@example.com` → 302 profile → `GET` profile 200.
- `POST /auth/login` with a wrong password stays off the profile (assert the response is not a 302 to `/resources/profile`).

### 7. `stack/dev-integ-7-oauth-approve`

Mode A, player logged in, client `integ_confidential`, scope `email`:

- `GET /oauth/authorize?...` while logged out → 301 or 302 to `/auth/login`.
- Same `GET` while logged in, first time for that client → redirect to `/oauth/approve`.
- `POST /oauth/approve` `action=deny` → 302 `/`.
- `POST /oauth/approve` `action=allow` → 302 to the redirect URI with `code` and `state`.

Do not exchange the code here. Milestone 13 does.

### 8. `stack/dev-integ-8-social-callbacks`

Mode A, three cases (Google, Facebook, Discord). Shared steps:

- `GET /auth/{provider}` → 302 whose `Location` host is the vendor, not the IDP.
- Do not follow it.
- Request the callback with the session jar, `code=integ-ok`, and the state from the session (parse it from the authorize `Location` query, or from the app’s state parameter on that redirect).
- Final hop is a 302 into the IDP and `GET /resources/profile` is 200 for the canned email.

One negative: `code=integ-deny` on the Google callback does not establish the canned Google user (profile is not 200 for that email).

### 9. `stack/dev-integ-9-apple-callback`

Overlay has Apple enabled.

- `GET /auth/apple` → 302 to Apple. Do not follow.
- `POST /auth/apple/callback` with the jar, `code=integ-ok`, and Apple’s `user` JSON containing `integ-apple@example.com`.
- Profile 200 for that user.

### 10. `stack/dev-integ-10-ork-and-profile`

Mode A:

- Mint a connect JWT. `GET /auth/connect?link_token=` returns 200 HTML. `POST /auth/connect/login` with the player password → 302 and the user is linked. A second POST with the same `jti` is rejected.
- Logged-in player `POST /resources/profile/link-ork` with `username=integ-ork` and the canned password → 302 `?success=linked`. This is the request that must hit `OrkService` through the fake. Assert the profile HTML shows the linked mundane.
- `POST /resources/profile/refresh-ork` → 302 `?success=refreshed`.
- `POST /resources/profile/unlink-ork` → 302 `?success=unlinked`.
- `POST /resources/profile/revoke` with `client_id=integ_confidential` → 302 `?success=revoked`.

### 11. `stack/dev-integ-11-management-ui`

Mode A as `integ-admin@example.com`:

- `GET /management/clients` 200 HTML.
- `POST /management/clients` creates client `integ_from_ui` → 302 back to the list, and a following GET contains that id.
- `POST /resources/clients/{id}/redirect` updates `redirect_uri`.
- `GET /management/users/search?q=integ` JSON lists the fixture user.
- `POST /management/clients/{id}/access` with the player email → 200, then delete → 200 `{ok:true}`.

Player `integ-player@example.com` `GET /management/clients` is not 200.

### 12. `stack/dev-integ-12-public-api`

Mode B, no cookie:

- `GET /version`, `GET /openapi.json`.
- `POST /api/is_authorized` with a policy that passes and one that fails.
- `GET /management/cleantokens?key=` with the overlay key → 200. Wrong key → 401 or 403 as the middleware already returns. Missing key length is not this test; the overlay key is long enough.

### 13. `stack/dev-integ-13-oauth-and-resources`

- Mode A allow-flow from milestone 7, capture `code`.
- Mode B `POST /oauth/token` `grant_type=authorization_code` → 200 `access_token` and `refresh_token`.
- `POST /oauth/token` `grant_type=refresh_token` → 200 new access token.
- `GET /resources/jwt` with the access token → 200 `jwt`.
- `GET /resources/userinfo` and `GET /resources/validate` with that authorization JWT → 200.
- `GET /resources/authorizations` with the Bearer token → 200.
- Repeat `GET /resources/jwt` with only the Mode A session cookie and no Bearer → 200.

Redis is real. A stale `pvh` case can wait; the happy path must not 409 on a token just issued.

### 14. `stack/dev-integ-14-client-iam`

Mode B as `integ_confidential`:

- `GET /resources/client/users/by-email?email=integ-player@example.com` → 200 with that user’s id.
- `POST /resources/client/policy-claims` with a valid provisos/resource for the client’s `iam_service` → 204. `GET` the list → 200 and the claim is present. `DELETE` → 204. `GET` again → claim absent.
- `PUT /resources/client/user-metadata` for that user and a real `login_id` → 204. `GET` → 200. `DELETE` → 204. `GET` → 404.
- `GET /resources/client/service-format` → 200. `POST` a format → 204. Second `POST` → 409. `PUT` → 204.
- `POST /resources/link-ork-profile` and `POST /resources/unlink-ork-profile` → 204. These do not call `OrkService`.

Basic auth for a client with an empty `iam_service` receives 403 on policy-claims and 200 on `GET /resources/client/service-format`. Seed that second client in this milestone’s test setup.

## Working rules for every milestone

- Stack the branch on the previous milestone. Do not open them in parallel.
- `src/` edits still go through `composer test` and Infection on the files touched. Do not lower the 95% / 80% gates. Container includes and `tests/Integration` are outside `src/` coverage.
- New decision branches in `src/` get a log line and a unit test that asserts that line. `DevIntegHttpClient` logs from the integ include; the integ suite asserts the HTTP result, not a log file.
- After milestone 5, `composer integ` is part of done. A path is not done because a unit test called the controller.
- Do not set `ENVIRONMENT=DEV_INTEG` in `.env` or `.env.example`. The integ overlay / `integ-up.sh` is the switch.
- `composer test` must remain integ-free; only `composer integ` or `./scripts/integ.sh` runs HTTP integration tests.
- Do not point the overlay at schema `idp`.
