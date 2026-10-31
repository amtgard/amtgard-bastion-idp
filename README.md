# amtgard-bastion-idp
Amtgard Identity Provider (https://idp.amtgard.com) is an OAuth 2.0 authorization server and an OpenID Provider for the authorization-code flow. It provides identity services for digital services (apps) for Amtgard and related boffer activities.

The basic concept is that Amtgard IDP converts your online account (such as google or facebook) into an Amtgard account. This account is shared across Amtgard apps such as the ORK, event management apps, and online forums.

The benefit of this is a single unified digital Amtgard account across apps and websites.

## Onboarding Your App

If you want to use the Amtgard IDP to manage authentication and authorization for you website or app, you will need an Amtgard IDP client configured. Right now, this is a manual process - please contact Megiddo to request access.

The IDP supports standard OAuth 2.0 clients (Confidential and Public) and OpenID Connect relying parties on the same authorization-code + PKCE login.

Confidential clients are clients where a *Client Secret* can be kept private and secure. Examples are websites where the client secret is kept secret and secure on the web server.

Public clients are client where the application lives entirely in the browser (aka SPA) or is installed on the user's device (such as a phone app).

- **ORK Service**: deeply integrated for fetching player profiles via the authorization-JWT resource flow.
- **Generic OAuth 2.0 clients**: any compliant OAuth 2.0 client can be registered in the `clients` database table. Omit `openid` and the token JSON stays `{access_token, refresh_token}`.
- **OpenID Connect relying parties**: send `scope` including `openid` (and usually a `nonce`) to receive an RS256 `id_token` on the same code exchange.

If you need onboarding help, please reach out. We host several example implementations on github for your reference: https://github.com/amtgard/amtgard-idp-client-examples

PHP relying-party examples stay out of this repo. If a later example app needs an OpenID Connect client, point [`jumbojett/openid-connect-php`](https://github.com/jumbojett/OpenID-Connect-PHP) at the issuer (`https://idp.amtgard.com`). Do not add that package here.

Integrator details for both paths: [templates/api.md](templates/api.md).

## OAuth Server Operations

The OAuth Server offers several resources:
* **OpenID Connect identity**: `id_token` on `/oauth/token` when `openid` was granted, plus `/oauth/userinfo` with the access token. Identity claims only (`sub`, and scoped `email` / `name` / `preferred_username` / `updated_at`).
* **User Info (authorization JWT)**: email, persona, and ORK-related information. This information can and should be stored and cached locally. This endpoint is rate limited at a relatively low level.

The IDP provides specific endpoints for retrieving user data and validating sessions.

### OpenID Connect

Production issuer: `https://idp.amtgard.com` (no path, no trailing slash). Discovery and JWKS are public:

- `GET /.well-known/openid-configuration`
- `GET /.well-known/jwks.json`

A relying party that only needs to know who logged in:

1. Redirect the browser to `/oauth/authorize` with `response_type=code`, PKCE (`S256`), `scope` including `openid` (and usually `profile email`), and a `nonce` (1–255 characters).
2. Exchange the code at `POST /oauth/token`. The JSON adds `id_token` next to the existing `access_token`, `refresh_token`, `expires_in`, and `token_type`. Without `openid`, those keys are unchanged.
3. Verify the RS256 `id_token` against JWKS. Check `iss`, `aud`, `exp`, and `nonce`. `iss` is `APP_URL`. `sub` is the user UUID (`users.user_id`). `exp` matches the access-token expiry. Refresh grants that still carry `openid` return a new `id_token` without `nonce`.
4. Optional: `GET` or `POST /oauth/userinfo` with the **access token**. The body is `sub` plus the same scoped identity claims. An authorization JWT is `401`. Missing `openid` is `403`.

`id_token` and `/oauth/userinfo` do not include ORK profile or IAM policy (`policy`, `pvh`, `orkid`, `orkuser`, `client_metadata`).

`prompt=none` is honored for silent checks: no session → `error=login_required`; session but no prior approval → `error=consent_required`. Neither case renders login or approve HTML. Other `prompt` values are ignored. `none` plus any other prompt value is `invalid_request`.

### User Info Endpoint
**Endpoint**: `/resources/userinfo`
- **Purpose**: Retrieves the full Amtgard profile of the authenticated user. This stays the **authorization-JWT** profile endpoint — not OpenID UserInfo.
- **Use Case**: Used by clients (like the ORK or a user profile page) to display user details, including linked Amtgard ORK profile data (Mundane ID, Persona, Park, Kingdom, etc.).
- **Auth**: `Authorization: Bearer <authorization_jwt>` from `GET /resources/jwt` (present the OAuth access token there first). Do not send this JWT to `/oauth/userinfo`.
- **Response**: JSON object containing `id`, `email`, and `ork_profile` (if linked).

### Validate Endpoint
**Endpoint**: `GET /resources/validate` (`LowLatencyController`; no route middleware)
- **Purpose**: Heartbeat / presence using the **authorization JWT** (fat `jwt` or `compact_jwt` from `GET /resources/jwt`), not a League OAuth access token.
- **Use Case**: High-frequency liveness checks without loading full profile data from `GET /resources/userinfo`.
- **Behavior**:
  - Validates Bearer JWT signature, issuer, and `pvh`; seeds Redis on cache miss (userinfo does not).
  - Enqueues PVH refresh work and publishes a PubSub presence event on success.
  - Returns minimal JSON (`id`, `email`; optional echoed Bearer when `?jwt=1`).
- **Differentiation**: `userinfo` accepts an OAuth access token via middleware fallback; validate does not. See `agent/cursor/dev-integ-validate-endpoint.md` and `templates/api.md`.

## Development

Local Docker uses the same three Compose projects as production (sessions, one web slot, jwt-worker). The web and worker overlays add `Dockerfile.dev`, a repo bind-mount, Xdebug on the web container (host port 41093), and a local MariaDB (`amtgard-idp-db`, volume `amtgard-idp_data-db`). Images require **PHP 8.4.27+** (2026-10-21 critical RCE advisories [CVE-2026-109618](https://www.php.net/archive/2026.php#2026-10-21-5), CVE-2026-109619); rebuild dev/prod images after pulling this commit so the ondrej PPA packages refresh. Copy `.env.example` to `.env` (non-secret config). One-time per clone: `cp .greenblue.example.dev .greenblue` (see [host-env/README.md](host-env/README.md) and the full runbook [docs/deploy-gb-host-env-vault.md](docs/deploy-gb-host-env-vault.md)).

Start or refresh the stack from **any working directory** (same as [generic-green-blue-deployment](https://github.com/your-org/generic-green-blue-deployment) operator UX):

```bash
./scripts/setup-git-hooks.sh   # once per clone — auto-updates VERSION on commit
composer install
composer frontend:build        # after changing templates/CSS; commits include public/assets/idp.css
/path/to/generic-green-blue-deployment/bin/gb /full/path/to/amtgard-idp deploy
docker exec amtgard-idp bash -lc \
  "cd /var/www/idp.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate"
```

With `export GB_PROJECT_ROOT=/full/path/to/amtgard-idp`, `gb deploy` is enough.

Local stack is **generic-green-blue only** (same as Denarius): dev mode in `.greenblue` starts sessions, bind-mounted web slot, jwt-worker, and host-env-vault sync/render on deploy when enabled (`GB_HEV_MOUNT_MAP` → `.gb/runtime.env`). Re-run the same `gb … deploy` after code or vault-catalog changes; `gb … revert` is prod-only.

Stacks: `amtgard-idp-sessions`, `amtgard-idp` (37080 + `amtgard-idp-db` on 36306), `amtgard-idp-jwt-worker`.

Do not start a second worker with `exec php bin/jwt-pvh-worker.php` in the app container. Do not `docker compose down -v` (that deletes `amtgard-idp_data-db`).

Server: http://localhost:37080/

`.env` is gitignored. Production servers also keep a single `.env` on the host (never committed).

### Integration tests (DEV_INTEG)

HTTP integration runs against the live app on port 37080 with `ENVIRONMENT=DEV_INTEG` (fake OAuth/ORK HTTP, dedicated integ MariaDB schema `idp`). Unit tests stay on `composer test`; integration is opt-in.

Integration uses a **parallel Docker Compose project** (`amtgard-idp-integ`) for dedicated MariaDB and session Redis, alongside the normal dev web stack (`amtgard-idp`). Both attach to the external network `amtgard-idp-shared`. Integ infra volumes are separate from dev (`amtgard-idp-integ-data-db`, `amtgard-idp-integ-session-data`).

| Service | Container | Host port (MariaDB only) |
|---------|-----------|--------------------------|
| Integ MariaDB | `amtgard-idp-db-integ` | `36307` → 3306 |
| Integ session Redis | `amtgard-idp-sessions-integ` | (internal on shared network) |
| Dev MariaDB (unchanged) | `amtgard-idp-db` | `36306` → 3306 |

Start integ infra only (no app overlay):

```bash
docker compose -p amtgard-idp-integ -f docker/compose.integ-infra.yml up -d
```

Full harness (starts integ infra, then dev sessions + web integ overlay with `DB_HOST=amtgard-idp-db-integ` and `SESSION_REDIS_HOST=amtgard-idp-sessions-integ`):

```bash
./scripts/integ.sh          # integ-up → composer integ → integ-down
./scripts/integ.sh --keep   # leave the stack in DEV_INTEG after tests
./scripts/integ-up.sh       # integ infra + overlay (migrate + seed)
composer integ              # PHPUnit against http://localhost:37080
./scripts/integ-down.sh     # restore ENVIRONMENT=DEV and the normal idp schema
```

Optional env overrides for scripts: `INTEG_PROJECT`, `INTEG_DB_CONTAINER`, `NETWORK`.

`integ.sh` fails if Docker is not running. On failure it still runs `integ-down` unless you passed `--keep`.

**Integration line coverage (spike):** PCOV inside integ php-fpm while the host runs `composer integ`. See [agent/cursor/integ-coverage.md](agent/cursor/integ-coverage.md). Quick run: `composer integ:coverage` → `build/integ-coverage/html/index.html`.

### Versioning

Each commit records an orderable build id in `VERSION` and `version.json` (format: `YYYY-MM-DD.<revision>`, e.g. `2026-06-19.847`). The app displays `YYYY-MM-DD.<revision>+<short-sha>` by reading the current git HEAD at runtime. The revision number increases with each commit on the branch; compare it to `main` to see if production is behind.

- Enable locally: `./scripts/setup-git-hooks.sh` (once per clone)
- Refresh manually: `./scripts/write-version.sh`
- Production: `GET /version` returns JSON; page footers show the same string
- Deploy: `install.sh` verifies `VERSION` matches the pulled commit

### Tests (PHPUnit in Docker)

PHPUnit requires PHP 8.4 (matches `composer.json`). With the dev stack up (`gb /full/path/to/amtgard-idp deploy`):

```bash
docker compose --project-directory . -p amtgard-idp \
  -f docker/compose.prod.yml -f docker/compose.blue.yml -f docker/compose.dev.yml \
  --profile test run --rm test
```

Or against the running app container:

```bash
docker exec amtgard-idp bash -lc \
  "cd /var/www/idp.amtgard.com && composer test"
```

## Production

Production uses **generic-green-blue-deployment** (`gb`) with `GB_MODE=prod` in a host-local `.greenblue` (copy from `.greenblue.example.prod`; never commit). Detailed steps: [docs/deploy-gb-host-env-vault.md](docs/deploy-gb-host-env-vault.md). Blue-green Docker slots (37080 / 37081) sit behind host nginx. Each slot is its own Compose project and **image**: `gb deploy` pulls `main`, optionally syncs/renders **host-env-vault** inject trees (no runtime vault socket), builds the inactive slot, runs `composer install` + Phinx in that container, health-checks, switches nginx, and leaves the previous slot running for rollback.

Non-secret config stays in host `.env`. OAuth and DB credentials come from vault **inject-only** profiles (`idp-http`, `idp-worker`) mounted at `/run/secrets` via `GB_HEV_MOUNT_MAP` → `.gb/runtime.env`. Legacy `keys/` / `dev-keys/` binds are optional fallbacks when inject files are not used. **MySQL is host-owned** (no production MySQL Docker volume).

Persistent sidecars (not blue/green slots):

- **Sessions Redis** (`amtgard-idp-sessions`): sessions (DB 1) and queue/cache (DB 0).
- **JWT PVH worker** (`amtgard-idp-jwt-worker`): tagged from the inactive slot image before cutover (`scripts/gb-after-slot-up.sh`).

One-time on the server:

```bash
sudo git clone … /var/gb/generic-green-blue-deployment   # or your toolkit path
cd /var/www/idp.amtgard.com
cp .greenblue.example.prod .greenblue
# edit GB_HEV_CONFIG / paths if needed
```

**Routine deploy:**

```bash
sudo ./install.sh
# thin wrapper → gb /var/www/idp.amtgard.com deploy
```

With vault (recommended):

```bash
sudo GB_HEV_CONFIG=/etc/host-env-vault/config.toml ./install.sh
# or: gb --hev-config /etc/host-env-vault/config.toml "$PWD" deploy
```

**Fast rollback** (nginx only — previous slot still running):

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/idp.amtgard.com revert
```

**Redeploy a pinned commit** (rebuild inactive slot + jwt-worker; Phinx does **not** down-migrate):

```bash
sudo ./install.sh 9f3c1ab
# or: sudo GB_INSTALL_GIT_REF=9f3c1ab ./install.sh
```

Session / worker maintenance (`INSTALL_*` or `GB_*` env vars):

```bash
sudo INSTALL_RESET_SESSIONS=1 ./install.sh
sudo INSTALL_REBUILD_SESSIONS=1 ./install.sh
sudo INSTALL_REBUILD_WORKER=1 ./install.sh
```

Host nginx: `host/nginx.blue.conf`, `host/nginx.green.conf`. Vault catalog fragment: `host-env/`. Docker: `docker/`.

## Configuration
Copy the example environment file to get started:
```bash
cp .env.example .env
```

### Key Configuration Options
- **Application**: `APP_URL`, `APP_ENV`, `APP_SECRET`
- **Database**: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — local Docker uses `amtgard-idp-db`. Production `.env` must point at the **host-owned** MySQL instance (e.g. `host.docker.internal`). Do not add a production MySQL Docker volume.
- **Sessions**: `SESSION_REDIS_HOST`, `SESSION_REDIS_PORT`, `SESSION_REDIS_DB` — `amtgard-idp-sessions` in local Docker and production (DB 1).
- **Pub/sub queue & cache**: `REDIS_PUBSUB_HOST`, `REDIS_PUBSUB_PORT`, `REDIS_PUBSUB_DB`, `REDIS_PUBSUB_QUEUE_NAME` — same sessions host (DB 0, presence).
- **JWT PVH refresh queue**: `REDIS_PVH_QUEUE_NAME` (default `amtgard-idp-pvh`) — consumed by `amtgard-idp-jwt-worker`, not the presence queue.
- **OAuth**:
  - `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY`: Paths to RSA keys for signing tokens.
  - `OAUTH_ENCRYPTION_KEY`: Key for encrypting auth codes.
- **Social Login**: Credentials for Google, Facebook, Discord, and optionally Apple (`GOOGLE_CLIENT_ID`, etc.). Apple requires `APPLE_LOGIN_ENABLED=true` plus Service ID, Team ID, Key ID, and AuthKey `.p8` file.

