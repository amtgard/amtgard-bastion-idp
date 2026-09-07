# amtgard-bastion-idp
Amtgard Identity Provider (https://idp.amtgard.com) provides identity services for digital services (apps) for Amtgard and related boffer activities.

The basic concept is that Amtgard IDP converts your online account (such as google or facebook) into an Amtgard account. This account is shared across Amtgard apps such as the ORK, event management apps, and online forums.

The benefit of this is a single unified digital Amtgard account across apps and websites.

## Onboarding Your App

If you want to use the Amtgard IDP to manage authentication and authorization for you website or app, you will need an Amtgard IDP client configured. Right now, this is a manual process - please contact Megiddo to request access.

The IDP supports standard OAuth2 clients (Confidential and Public).

Confidential clients are clients where a *Client Secret* can be kept private and secure. Examples are websites where the client secret is kept secret and secure on the web server.

Public clients are client where the application lives entirely in the browser (aka SPA) or is installed on the user's device (such as a phone app).

- **ORK Service**: deeply integrated for fetching player profiles.
- **Generic OAuth2 Clients**: Any compliant OAuth2 client can be registered in the `clients` database table.

If you need onboarding help, please reach out. We host several example implementations on github for your reference: https://github.com/amtgard/amtgard-idp-client-examples

## OAuth Server Operations

The OAuth Server offers several resources:
* **User Info**: email, persona, and ORK-related information. This information can and should be stored and cached locally. This endpoint is rate limited at a relatively low level.
* **

The IDP provides specific endpoints for retrieving user data and validating sessions.

### User Info Endpoint
**Endpoint**: `/resources/userinfo`
- **Purpose**: Retrieves the full profile of the authenticated user.
- **Use Case**: Used by clients (like the ORK or a user profile page) to display user details, including linked Amtgard ORK profile data (Mundane ID, Persona, Park, Kingdom, etc.).
- **Response**: JSON object containing `id`, `email`, and `ork_profile` (if linked).

### Validate Endpoint
**Endpoint**: `/resources/validate` (or `/oauth/validate`)
- **Purpose**: A lightweight endpoint to quickly validate an Access Token and register "liveness".
- **Use Case**: Used by clients to check if a user's session is still active without fetching the full profile.
- **Behavior**:
  - Checks if the user is in the Redis cache.
  - Triggers a PubSub event to notify other services that the user is online/active.
  - Returns minimal user data (`id`, `email`).
- **Differentiation**: unlike `userinfo`, `validate` is optimized for high-frequency "heartbeat" checks and presence tracking.

## Development

Local Docker uses the same three Compose projects as production (sessions, one web slot, jwt-worker). The web and worker overlays add `Dockerfile.dev`, a repo bind-mount, Xdebug on the web container (host port 41093), and a local MariaDB (`amtgard-idp-db`, volume `amtgard-idp_data-db`). Copy `.env.example` to `.env`, fill in secrets, then from the **repository root**:

```bash
./scripts/setup-git-hooks.sh   # once per clone — auto-updates VERSION on commit
composer install
./scripts/dev-up.sh
docker exec amtgard-idp bash -lc \
  "cd /var/www/idp.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate"
```

`scripts/dev-up.sh` creates `amtgard-idp-shared` if needed, then `up -d`s:

1. `amtgard-idp-sessions` — `docker/compose.sessions.yml` (`amtgard-idp-sessions`)
2. `amtgard-idp` — `compose.prod.yml` + `compose.blue.yml` + `compose.dev.yml` (`amtgard-idp` on 37080, `amtgard-idp-db` on 36306)
3. `amtgard-idp-worker` — `compose.worker.yml` + `compose.worker.dev.yml` (`amtgard-idp-jwt-worker`)

Do not start a second worker with `exec php bin/jwt-pvh-worker.php` in the app container. Do not `docker compose down -v` (that deletes `amtgard-idp_data-db`).

Server: http://localhost:37080/

`.env` is gitignored. Production servers also keep a single `.env` on the host (never committed).

### Versioning

Each commit records an orderable build id in `VERSION` and `version.json` (format: `YYYY-MM-DD.<revision>`, e.g. `2026-06-19.847`). The app displays `YYYY-MM-DD.<revision>+<short-sha>` by reading the current git HEAD at runtime. The revision number increases with each commit on the branch; compare it to `main` to see if production is behind.

- Enable locally: `./scripts/setup-git-hooks.sh` (once per clone)
- Refresh manually: `./scripts/write-version.sh`
- Production: `GET /version` returns JSON; page footers show the same string
- Deploy: `install.sh` verifies `VERSION` matches the pulled commit

### Tests (PHPUnit in Docker)

PHPUnit requires PHP 8.4 (matches `composer.json`). After `./scripts/dev-up.sh`:

```bash
./scripts/dev-up.sh test
```

Or against the running app container:

```bash
docker exec amtgard-idp bash -lc \
  "cd /var/www/idp.amtgard.com && composer test"
```

## Production

Production uses blue-green Docker (blue on port 37080, green on 37081) behind host nginx. Each slot is a separate Compose project and **image**: `install.sh` pulls `origin/main`, builds the inactive slot from that tree, health-checks it, then switches host nginx. The live slot keeps serving its previously built image until cutover.

Host `.env`, `dev-keys/`, and `keys/` are bind-mounted into both containers (shared config and signing keys). Application code and `vendor/` are not. **MySQL is host-owned** (production does not run a MySQL Docker volume). Phinx migrations still run against that live database before nginx switches.

Two isolated Compose projects stay up across slot switches — they are not blue/green slots:

- **Sessions Redis** (`amtgard-idp-sessions`): PHP sessions (DB 1) and app queue/cache (DB 0). Started once so deploys do not log users out or drop in-flight queue work.
- **JWT PVH worker** (`amtgard-idp-worker`, container `amtgard-idp-jwt-worker`): CLI consumer of `REDIS_PVH_QUEUE_NAME` on the same Redis DB 0. Reaches host MySQL via `host.docker.internal`. After Phinx migrate on the inactive slot, `install.sh` tags that slot's image as `amtgard-idp-jwt-worker:latest` and `up -d`s the worker before the health check / nginx switch.

**First deploy after upgrading install.sh — run twice:**

```bash
sudo ./install.sh
```

Host: `git pull`, `chown`. Inactive slot: image build (code + `composer install`), `phinx migrate`, health check, nginx switch.

**Routine deploys — run once:**

```bash
sudo ./install.sh   # builds inactive slot, migrates, switches host nginx
```

Optional session store maintenance:

```bash
# Log everyone out (flush sessions, keep container + volume)
sudo INSTALL_RESET_SESSIONS=1 ./install.sh

# Rebuild session container and wipe all persisted session data
sudo INSTALL_REBUILD_SESSIONS=1 ./install.sh

# Recreate the jwt-worker container (same image tag, force recreate)
sudo INSTALL_REBUILD_WORKER=1 ./install.sh
```

Host nginx configs: `host/nginx.blue.conf`, `host/nginx.green.conf`. Docker configs: `docker/`.

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

