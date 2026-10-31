# Amtgard IDP + host-env-vault (local dev)

Tier profiles: **idp-http** (web / php-fpm slot) and **idp-worker** (JWT PVH CLI). Both are **inject-only** (`runtime` empty, `socket: false`). OAuth PEMs use `inject_files`; no runtime vault socket in this stack.

See also [agent/cursor/host-env-gb/README.md](../agent/cursor/host-env-gb/README.md).

## One-time laptop setup

1. Bootstrap shared vault (from the **host-env-vault** checkout):

   ```bash
   cd ../host-env-vault   # or your host-env-vault root
   ./scripts/local-shared-bootstrap.sh
   ```

2. IDP catalog fragment — `scripts/gb-pre-hev-sync.sh` (from `.greenblue.example.dev`) copies `host-env/catalog.d/40-idp.json` into the vault’s `dev-shared/catalog.d/` and refreshes `secrets/catalog.json` when merge scripts exist.

   Without green-blue, install the fragment manually:

   ```bash
   cp host-env/catalog.d/40-idp.json \
      ../host-env-vault/dev-shared/catalog.d/40-idp.json
   cd ../host-env-vault && ./scripts/merge-catalog.sh
   ```

   **Local dev mode:** `host-config.toml` uses `[sync] source = "local"`. Match that with `GB_HEV_SYNC_SOURCE=local` in `.greenblue` (see `.greenblue.example.dev` in M2).

   **Render output:** vault must finish with **`secrets.env`** (and `files/` for PEMs). A leftover **`secrets.partial`** only means render failed mid-write — fix vault render; do not teach the app to read it.

3. Seed secret **values** (gitignored on the vault side):

   ```bash
   cp host-env/secrets.example/idp-http.env \
      ../host-env-vault/dev-shared/secrets/idp-http.env
   cp host-env/secrets.example/idp-worker.env \
      ../host-env-vault/dev-shared/secrets/idp-worker.env
   ```

   Edit those files with real values. Copy OAuth keys and dev-only `apple.p8` per [secrets.example/files/README.md](./secrets.example/files/README.md).

   **OAuth / sync shortcut (laptop):** after `dev-keys/` exists, run `./scripts/refresh-dev-vault-idp-http.sh`. It copies PEMs into the vault `files/` tree, merges non-empty repo `.env` into `idp-http.env` (empty inject values break `host-env-vault sync`), re-renders `idp-http`, and recreates the dev web container. Re-run whenever OAuth signing fails after clone or placeholder PEMs.

   **Shared vault:** `dev-shared/catalog.d` also merges example **app-web** / **api-http** profiles. `scripts/gb-pre-hev-sync.sh` copies missing `secrets.example/*.env` and `files/*` into `dev-shared/secrets/` so local `sync` does not fail for those profiles. You can still set `GB_HEV_SYNC_STRICT=0` to continue if sync warns.

   **PHP:** `config/bootstrap.php` loads `config/di-container.php` (PHP-DI, including PROD compilation) then `config/load-env.php`. Shared `ENVIRONMENT` helpers live in `config/idp-environment.php` only — do not duplicate them in `load-env.php` or bootstrap fatals on redeclare. `load-env.php` applies immutable Dotenv for repo `.env`, then mutable Dotenv for `/run/secrets/secrets.env` (inject overrides overlapping keys). When `ENVIRONMENT=DEV`, every key listed in repo `.env` wins over inject (MariaDB password, `GOOGLE_CLIENT_SECRET`, etc.) so stale laptop vault bundles do not break local login. `ENVIRONMENT=DEV_INTEG` (integration Docker overlay) skips inject entirely; OAuth PEM paths come from `docker/compose.integ.yml`.

4. Configure **generic-green-blue** once per clone: `cp .greenblue.example.dev .greenblue` (edit `GB_HEV_*` if the vault is not at `../host-env-vault/dev-shared`).

5. Deploy from any directory — `gb` takes the **absolute app root** as its first argument (vault sync/render runs inside that deploy when enabled in `.greenblue`):

   ```bash
   /path/to/generic-green-blue-deployment/bin/gb /full/path/to/amtgard-idp deploy
   ```

   Compose mount wiring comes from `GB_HEV_MOUNT_MAP` → `{repo}/.gb/runtime.env` (not application `.env`). Before M4 compose wiring, an empty fallback mount dir lives at `.local/empty-secrets/` (tracked via `.gitkeep`).

## Profiles

| Profile | Mount role | Inject env (catalog) | Inject files |
| --- | --- | --- | --- |
| `idp-http` | Blue/green web slot | `APP_SECRET`, `JWT_KEY`, `OAUTH_ENCRYPTION_KEY`, DB, Defuse, ORK shared secret, management key, mailbox pepper, social + mail provider secrets | `oauth-private.key`, `oauth-public.key`, `apple.p8` (dev; omit on prod hosts without Apple Sign In) |
| `idp-worker` | JWT PVH worker compose project | `DB_PASS` | — |

## Production

Copy `.greenblue.example.prod` to the host app directory (M2). Deploy from the toolkit install:

```bash
/var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/idp.amtgard.com deploy
```

## Migration from plain `.env`

| Phase | Behavior |
| --- | --- |
| **Now** | `.env` non-secrets (immutable `safeLoad`); `/run/secrets/secrets.env` (mutable `load`) overrides overlapping keys. |
| **Next** | Move provider and crypto secrets out of `.env` into vault profile env files; keep hosts, ports, client IDs, and feature flags in `.env`. |
| **Prod** | `gb deploy` renders inject trees before the inactive slot starts; worker uses `idp-worker` inject only. |

PHPUnit and `APP_ENV=testing` continue to use `phpunit.xml` env defaults; no vault mount required.
