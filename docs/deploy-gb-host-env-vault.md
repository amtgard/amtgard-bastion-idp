# Deploy: generic-green-blue + host-env-vault (Amtgard IDP)

Operator runbook for **interrupt-free** deploys: install the green-blue toolkit and vault agent, stand up a **local intermediary vault** on a laptop (before AWS SSM on production), validate inject mounts in Docker, then use **`gb deploy`** / **`gb revert`** on the bastion.

This stack uses **inject-only** vault profiles (`idp-http`, `idp-worker`). There is **no** runtime vault unix socket in the IDP compose path. Secrets arrive as **`/run/secrets/secrets.env`** plus **`/run/secrets/files/*`** inside containers.

Related docs:

- [host-env/README.md](../host-env/README.md) — catalog fragment, profile fields, quick local steps
- [agent/cursor/host-env-gb/README.md](../agent/cursor/host-env-gb/README.md) — milestone scope and checklist
- [generic-green-blue-deployment](https://github.com/your-org/generic-green-blue-deployment) — toolkit README (install path, hooks, HEV flags)
- [host-env-vault](https://github.com/your-org/host-env-vault) — sync, render, SSM transition

---

## Architecture (what happens on `gb deploy`)

```text
┌─────────────────────────────────────────────────────────────────┐
│ Host (laptop or bastion)                                         │
│  .greenblue          gb deploy                                   │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  host-env-vault sync ──► .hev bundles in state_dir               │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  host-env-vault render ► {render_root}/{profile}/current/        │
│                          secrets.env + files/                    │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  GB_HEV_MOUNT_MAP ─────► .gb/runtime.env                         │
│  (idp-http → IDP_HEV_HTTP_SECRETS, etc.)                         │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  docker compose ────────► mount …/current → /run/secrets:ro      │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  PHP bootstrap ─────────► load-env.php reads repo .env then      │
│                           /run/secrets/secrets.env               │
└─────────────────────────────────────────────────────────────────┘
```

**Blue-green (production):** inactive slot (blue or green) is built and health-checked on ports **37080** / **37081**; host nginx switches to the new slot; the previous slot stays up for **`gb revert`**. Sessions Redis and the JWT PVH worker are **sidecars**, not slot-swapped.

**Dev:** bind-mounted source, same three stacks (sessions, web, worker), HEV sync/render on **`gb deploy`** when enabled in `.greenblue.example.dev`.

---

## Prerequisites

| Requirement | Dev (laptop) | Production (bastion) |
| --- | --- | --- |
| Docker + Compose v2 | Yes | Yes |
| bash 3.2+ | Yes | Yes |
| **generic-green-blue-deployment** checkout | Sibling repo or `/var/gb/…` | `/var/gb/generic-green-blue-deployment` |
| **host-env-vault** binary | Built from repo or on `PATH` | Installed under `/usr/local/bin` or via package |
| App repo | `amtgard-idp` clone | `/var/www/idp.amtgard.com` (typical) |
| Secrets | `host-env-vault/dev-shared/secrets/` (gitignored) | SSM + `/etc/host-env-vault/config.toml` |
| Host `.env` | Repo `.env` (non-secrets + dev overrides) | Host `.env` (URLs, client IDs, DB host — not vault crypto) |
| PHP image | **8.4.27+** (Dockerfiles gate CVE-2026-109618/619) | Same |

---

## Phase 1 — Install generic-green-blue (`gb`)

One-time per machine:

```bash
sudo mkdir -p /var/gb
sudo git clone https://github.com/your-org/generic-green-blue-deployment.git \
  /var/gb/generic-green-blue-deployment
```

Dev alternative (sibling checkout):

```bash
git clone …/generic-green-blue-deployment ../generic-green-blue-deployment
export GREENBLUE_BIN="../generic-green-blue-deployment/bin/gb"
```

Smoke-test the toolkit:

```bash
/var/gb/generic-green-blue-deployment/tests/smoke.sh
# expect: smoke: ok
```

Invoke **`gb` by absolute path** (or set `GREENBLUE_BIN` / `PATH`). First argument is always the **absolute application root** (directory that contains `.greenblue`).

---

## Phase 2 — Install host-env-vault

### Laptop (local intermediary store)

From your **host-env-vault** checkout:

```bash
cd /path/to/host-env-vault
cargo build --release
# optional: cargo install --path . --locked
./scripts/local-shared-bootstrap.sh
```

That creates **`dev-shared/`** layout: `host-config.toml`, `catalog.d/`, `secrets/`, `state/`, `run/`. For laptop work, configure **`[sync] source = "local"`** (or use **`host-store`** after seeding — see vault README). Match **`.greenblue`**: `GB_HEV_SYNC_SOURCE=local` in dev.

Put the binary on `PATH`, or set in `.greenblue`:

```bash
GB_HEV_REPO="/path/to/host-env-vault"
# gb invokes host-env-vault from GB_HEV_REPO when GB_HEV_BIN unset
```

### Production (SSM — later cutover)

On the Linux host (not on first laptop trial):

1. Run **`./scripts/setup-profile.sh`** (or manual steps in host-env-vault README).
2. Install systemd units: **`host-env-vault-sync.timer`**, render before deploy via **`gb`** (`GB_HEV_SYNC_ON_DEPLOY=1`, `GB_HEV_RENDER_ON_DEPLOY=1`).
3. Config file typically **`/etc/host-env-vault/config.toml`** with **`[sync] source = "ssm"`**.
4. Migrate secrets from laptop store using **`docs/transition.md`** in the vault repo.

Do **not** point production at `dev-shared/` on Google Drive; use SSM + host state dirs under `/var/lib/host-env-vault`.

---

## Phase 3 — Configure the local intermediary vault (before SSM)

All paths below are relative to the **amtgard-idp** repo unless noted.

### 3.1 Copy the IDP catalog fragment

On every clone (or automatically via hook):

```bash
cp host-env/catalog.d/40-idp.json \
   /path/to/host-env-vault/dev-shared/catalog.d/40-idp.json
cd /path/to/host-env-vault && ./scripts/merge-catalog.sh
```

**`scripts/gb-pre-hev-sync.sh`** (sourced from `.greenblue.example.dev` and **`GB_HOOK_PRE_DEPLOY`** in prod) performs this when `GB_HEV_VAULT_ROOT` resolves to a tree with `dev-shared/catalog.d/`.

Profiles:

| Profile | Container role | Inject |
| --- | --- | --- |
| `idp-http` | Web / php-fpm slot | DB, crypto, social secrets, mail; files `oauth-private.key`, `oauth-public.key`, optional `apple.p8` |
| `idp-worker` | JWT PVH worker | `DB_PASS` only |

Catalog source: [host-env/catalog.d/40-idp.json](../host-env/catalog.d/40-idp.json).

### 3.2 Seed secret values (gitignored on vault side)

```bash
cp host-env/secrets.example/idp-http.env \
   /path/to/host-env-vault/dev-shared/secrets/idp-http.env
cp host-env/secrets.example/idp-worker.env \
   /path/to/host-env-vault/dev-shared/secrets/idp-worker.env
```

Edit with real values. **Empty values break `host-env-vault sync`** — every inject key in the catalog must be non-empty in the env file or store.

Copy PEMs per [host-env/secrets.example/files/README.md](../host-env/secrets.example/files/README.md).

**Shortcut after `dev-keys/` exists:**

```bash
./scripts/refresh-dev-vault-idp-http.sh
```

This copies OAuth PEMs into the vault `files/` tree, merges non-empty keys from repo **`.env`** into `idp-http.env`, runs sync/render for `idp-http`, and recreates the dev web container.

### 3.3 Application green-blue config

```bash
cp .greenblue.example.dev .greenblue   # never commit .greenblue
cp .env.example .env                   # non-secrets; gitignored
```

If vault is not at `../host-env-vault`, set in `.greenblue`:

```bash
GB_HEV_VAULT_ROOT="/absolute/path/to/host-env-vault"
GB_HEV_CONFIG="${GB_HEV_VAULT_ROOT}/dev-shared/host-config.toml"
```

Dev example already sets:

- `GB_HEV_PROFILES="idp-http idp-worker"`
- `GB_HEV_SYNC_ON_DEV_UP=1`, `GB_HEV_RENDER_ON_DEV_UP=1`
- `GB_HEV_SYNC_SOURCE=local`
- `GB_HEV_MOUNT_MAP="idp-http:IDP_HEV_HTTP_SECRETS idp-worker:IDP_HEV_WORKER_SECRETS"`

At the bottom of `.greenblue.example.dev`, **`gb-pre-hev-sync.sh`** runs once when the file is sourced (dev catalog refresh).

---

## Phase 4 — Test local env in Docker (offline container path)

### 4.1 Build frontend assets (UI)

```bash
composer install
composer frontend:build
```

### 4.2 Deploy via gb

From any working directory:

```bash
/path/to/generic-green-blue-deployment/bin/gb /full/path/to/amtgard-idp deploy
```

Optional convenience:

```bash
export GB_PROJECT_ROOT=/full/path/to/amtgard-idp
gb deploy   # if gb is on PATH and resolves project root
```

**What to expect:**

1. **`gb-pre-hev-sync.sh`** updates vault catalog fragment (if vault root found).
2. **`host-env-vault sync`** + **`render`** for each profile in `GB_HEV_PROFILES`.
3. **`GB_HEV_MOUNT_MAP`** writes **`{repo}/.gb/runtime.env`**, e.g.:

   ```bash
   IDP_HEV_HTTP_SECRETS=/path/to/host-env-vault/dev-shared/run/idp-http/current
   IDP_HEV_WORKER_SECRETS=/path/to/host-env-vault/dev-shared/run/idp-worker/current
   ```

4. Compose starts **sessions**, **web** (legacy dev container `amtgard-idp` on **37080**), **worker**.
5. Web/worker mounts: `${IDP_HEV_HTTP_SECRETS}:/run/secrets:ro` (see `docker/compose.dev.yml`, `docker/compose.worker.dev.yml`).

### 4.3 Verify inject inside the app container

```bash
docker exec amtgard-idp bash -lc 'test -f /run/secrets/secrets.env && head -1 /run/secrets/secrets.env | cut -c1-20'
docker exec amtgard-idp bash -lc 'test -f /run/secrets/files/oauth-private.key && echo ok'
curl -sf http://localhost:37080/ | head
```

Migrate DB if needed:

```bash
docker exec amtgard-idp bash -lc \
  'cd /var/www/idp.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate'
```

### 4.4 PHPUnit (optional gate)

```bash
docker exec amtgard-idp bash -lc 'cd /var/www/idp.amtgard.com && composer test'
```

Integration tests use **`ENVIRONMENT=DEV_INTEG`** and **do not** require vault mounts (`./scripts/integ.sh`).

### 4.5 DEV vs inject precedence (laptop)

When **`ENVIRONMENT=DEV`**, keys present in repo **`.env`** override inject for those keys so stale vault bundles do not break local login. **`ENVIRONMENT=DEV_INTEG`** skips inject entirely.

---

## Phase 5 — Production deploy and swap (`gb`)

### 5.1 One-time bastion setup

```bash
sudo git clone … /var/gb/generic-green-blue-deployment
sudo git clone … /var/www/idp.amtgard.com
cd /var/www/idp.amtgard.com
sudo cp .greenblue.example.prod .greenblue
# Edit .greenblue: GB_HEV_CONFIG, GB_STATE_DIR, nginx paths
sudo cp .env.example .env   # host paths, public client IDs, DB host — not vault secrets
```

Install and configure **host-env-vault** for SSM (Phase 2 production). Uncomment in `.greenblue.example.prod`:

```bash
GB_HEV_CONFIG="/etc/host-env-vault/config.toml"
GB_HEV_SYNC_ON_DEPLOY="1"
GB_HEV_RENDER_ON_DEPLOY="1"
# omit GB_HEV_SYNC_SOURCE (SSM default)
```

Ensure **`host-env/catalog.d/40-idp.json`** is merged into the production catalog (Parameter Store or host catalog sync — follow vault ops docs).

### 5.2 Routine deploy

```bash
cd /var/www/idp.amtgard.com
sudo GB_HEV_CONFIG=/etc/host-env-vault/config.toml ./install.sh
```

**`install.sh`** resolves **`gb`**, passes **`--hev-config`** when set, runs **`gb "$ROOT" deploy`**: pull `main`, sync/render vault, build **inactive** slot image, `composer install` + Phinx in container, health check, nginx cutover, jwt-worker tag hook.

Pinned SHA (rebuilds worker automatically):

```bash
sudo ./install.sh 9f3c1ab
```

### 5.3 Fast rollback (nginx only)

Previous slot containers remain running:

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/idp.amtgard.com revert
```

### 5.4 Session / worker maintenance

```bash
sudo INSTALL_RESET_SESSIONS=1 ./install.sh
sudo INSTALL_REBUILD_SESSIONS=1 ./install.sh
sudo INSTALL_REBUILD_WORKER=1 ./install.sh
```

Phinx **migrate** runs forward on deploy; **`install.sh` does not down-migrate** on revert.

---

## Migration plan: plain `.env` → vault → SSM

| Phase | Where secrets live | Operator action |
| --- | --- | --- |
| **A — Today** | Host `.env` + optional `keys/` | Legacy installs; not recommended for new prod |
| **B — Intermediary (laptop / staging)** | `host-env-vault/dev-shared` + **`GB_HEV_SYNC_SOURCE=local`** | Follow Phases 1–4; validate OAuth and DB via inject |
| **C — Prod inject, SSM backend** | SSM → sync → render → `/run/secrets` | Phase 5; strip secrets from `.env`; keep URLs and client IDs in `.env` |
| **D — Steady state** | Rotations in SSM; `host-env-vault sync` on timer + before deploy | No app redeploy for pure secret rotation if render + slot recycle policy allows |

---

## Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `Cannot redeclare function idp_runtime_environment()` | Duplicate ENV helpers in bootstrap/load-env | Only **`config/idp-environment.php`** defines helpers; bootstrap loads DI then **`load-env.php`** |
| `secrets.partial` under render dir | Render failed mid-write | Fix vault error; re-run sync/render; never point PHP at `.partial` |
| Empty inject / sync failure | Blank values in `idp-http.env` | Fill all catalog inject keys; run **`refresh-dev-vault-idp-http.sh`** |
| OAuth signing fails after clone | Placeholder PEMs or wrong mount | Refresh dev vault script; confirm **`/run/secrets/files/oauth-private.key`** in container |
| Compose uses empty secrets | Missing `.gb/runtime.env` | Run **`gb deploy`** with HEV enabled; check **`GB_HEV_MOUNT_MAP`** |
| `gb` not found | Toolkit not installed | Set **`GREENBLUE_BIN`** or install under **`/var/gb/…`** |
| Strict sync aborts deploy | Missing non-IDP profiles in shared dev catalog | **`gb-pre-hev-sync.sh`** seeds from **`secrets.example`**; or **`GB_HEV_SYNC_STRICT=0`** (dev only) |
| Home page 500 after mixed branches | Bootstrap load order / redeclare | Stay on consistent stack; run **`tests/Config/BootstrapConfigLoadTest.php`** |

---

## Pre-production checklist (IDP team)

Use this before the first bastion cutover with vault inject:

- [ ] **`generic-green-blue-deployment/tests/smoke.sh`** passes on the operator machine
- [ ] **`host-env-vault`** `cargo test` passes (use **`RUST_TEST_THREADS=1`** if a log test flakes)
- [ ] Laptop **`gb … deploy`** completes; **`curl localhost:37080/`** returns 200
- [ ] **`/run/secrets/secrets.env`** and OAuth PEMs visible in **`amtgard-idp`** container
- [ ] Login / token path smoke-tested with inject-backed secrets
- [ ] **`.greenblue.prod`** on bastion reviewed; **`GB_STATE_DIR`** writable
- [ ] SSM parameters and catalog match **`40-idp.json`**
- [ ] **`install.sh`** dry-run path documented for on-call (`revert`, pinned SHA)
- [ ] Secrets removed from committed files and prod `.env` (vault only)

---

## Out of scope for this runbook

- **Runtime vault socket** (`serve` profiles) — IDP uses inject-only
- **Docsify / Swagger CDN** pages — separate asset story
- **PHPUnit integ** vault mounts — integ uses **`DEV_INTEG`** fakes
- **MySQL in Docker on production** — prod DB is host-owned; see main README

---

## Quick reference commands

```bash
# Local full refresh
composer frontend:build
/path/to/generic-green-blue-deployment/bin/gb "$PWD" deploy

# Refresh OAuth + idp-http env from repo .env
./scripts/refresh-dev-vault-idp-http.sh

# Prod deploy
sudo GB_HEV_CONFIG=/etc/host-env-vault/config.toml ./install.sh

# Prod nginx rollback
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/idp.amtgard.com revert
```
