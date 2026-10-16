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

   **macOS render:** omit `"gid"` in the laptop catalog (as in `40-idp.json`) so `render` does not `chown root:www-data`. On Linux production hosts, merge `catalog.d/41-idp-linux-gid.json.example` (or equivalent) so each profile has `"gid": 33`.

3. Seed secret **values** (gitignored on the vault side):

   ```bash
   cp host-env/secrets.example/idp-http.env \
      ../host-env-vault/dev-shared/secrets/idp-http.env
   cp host-env/secrets.example/idp-worker.env \
      ../host-env-vault/dev-shared/secrets/idp-worker.env
   ```

   Edit those files with real values. Copy OAuth keys per [secrets.example/files/README.md](./secrets.example/files/README.md).

4. Configure **generic-green-blue** (once per machine; M2 adds `.greenblue.example.dev`):

   ```bash
   cp .greenblue.example.dev .greenblue
   # edit GB_HEV_* overrides if the vault is not at ../host-env-vault/dev-shared
   ```

5. Start the IDP (generic-green-blue). The first argument to `gb` is the **IDP repo root** (where `.greenblue` lives):

   ```bash
   cd /full/path/to/amtgard-idp
   ../generic-green-blue-deployment/bin/gb "$PWD" deploy
   ```

   Compose mount wiring comes from `GB_HEV_MOUNT_MAP` → `{repo}/.gb/runtime.env` (not application `.env`). Before M4 compose wiring, an empty fallback mount dir lives at `.local/empty-secrets/` (tracked via `.gitkeep`).

## Profiles

| Profile | Mount role | Inject env (catalog) | Inject files |
| --- | --- | --- | --- |
| `idp-http` | Blue/green web slot | DB, Defuse, ORK shared secret, management key, mailbox pepper, social + mail provider secrets | `oauth-private.key`, `oauth-public.key` |
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
| **Now** | `.env` holds non-secret config; rendered `secrets.env` on `/run/secrets` loads via Dotenv `safeLoad` (does not override `.env`). |
| **Next** | Move provider and crypto secrets out of `.env` into vault profile env files; keep hosts, ports, client IDs, and feature flags in `.env`. |
| **Prod** | `gb deploy` renders inject trees before the inactive slot starts; worker uses `idp-worker` inject only. |

PHPUnit and `APP_ENV=testing` continue to use `phpunit.xml` env defaults; no vault mount required.
