# host-env-vault + gb — work checklist

**Stack base:** `main` (first milestone), then previous milestone tip.

**Gates (IDP PHP milestones):** `composer test` (≥95% line coverage on `src/`), Infection MSI ≥80% on touched PHP, log-tested decision branches on new code, existing container/route health tests still pass.

**Do not push** unless the user asks.

---

## M0 — Docs: ORK pack + this plan

- [x] State clearly in ork-link-possession README: **linking always originates on the IDP** (profile Email Code / Flow A v2); legacy ORK `/auth/connect` is compat only
- [x] Land pending ork-link-possession README / contract / milestones / detailed-design updates (spike follow-up)
- [x] Add `agent/cursor/host-env-gb/README.md` and this checklist (initial version)
- [x] One commit on `stack/host-env-gb-m0-docs`; check M0 boxes; note branch + gates in checklist footer

---

## M1 — Vault catalog fragment (`host-env/`)

- [x] `host-env/catalog.d/40-idp.json` — profiles `idp-http`, `idp-worker`; inject-only; `inject_files`: `oauth-private.key`, `oauth-public.key` (no runtime, `socket: false`)
- [x] `host-env/catalog.d/41-idp-linux-gid.json.example` — optional `gid: 33` for prod hosts
- [x] `host-env/secrets.example/idp-http.env` and `idp-worker.env` (paths under `/run/secrets/files/…`)
- [x] `host-env/secrets.example/files/` — placeholder or README for oauth keys + **dev-only** `apple.p8`
- [x] `host-env/README.md` — copy fragment into vault `catalog.d`, seed local secrets, profile list
- [x] One commit on `stack/host-env-gb-m1-catalog`; check M1 boxes

---

## M2 — Green-blue config + scripts

- [x] `.greenblue.example.dev` — `GB_MODE=dev`, HEV local sync/render, `GB_HEV_PROFILES="idp-http idp-worker"`, `GB_HEV_MOUNT_MAP`, hooks, source `scripts/gb-greenblue-helpers.sh`
- [x] `.greenblue.example.prod` — SSM sync/render on deploy, host `GB_STATE_DIR`, same profiles (no connect)
- [x] `.gitignore` — `.greenblue`, `.gb/`
- [x] `scripts/gb-greenblue-helpers.sh`, `gb-pre-hev-sync.sh` (install `40-idp.json`), `gb-hev-after-render.sh`, `gb-after-slot-up.sh` (jwt-worker tag)
- [x] `.local/empty-secrets` pattern documented or mkdir in dev-up
- [x] One commit on `stack/host-env-gb-m2-greenblue`; check M2 boxes

---

## M3 — PHP inject bootstrap

- [x] `HostEnvBootstrap` + `InjectedSecretsLoader` under `src/Utility/Setup/HostEnv/` (Denarius pattern; map oauth basenames → `OAUTH_*` / `APPLE_KEY_FILE_PATH` for dev `.p8` only when file present)
- [x] `config/host-env-bootstrap.php`; wire `config/bootstrap.php` after immutable `.env`
- [x] `phinx.php` loads inject bootstrap (or equivalent) so migrate sees `DB_PASS` from mount
- [x] Unit tests + log tests for skip vs apply paths
- [x] One commit on `stack/host-env-gb-m3-hostenv-php`; check M3 boxes; record coverage + Infection in footer

---

## M4 — Compose + env wiring

- [x] `docker/compose.prod.yml` — mount `${IDP_HEV_HTTP_SECRETS:-./.local/empty-secrets}:/run/secrets:ro`; `HOST_ENV_SECRETS_DIR=/run/secrets`; drop or optionalize `keys/` / `dev-keys/` binds when inject files suffice
- [x] `docker/compose.worker.yml` — worker secrets mount + `HOST_ENV_SECRETS_DIR`
- [x] `.env.example` — document non-secret vs vault; HEV comments; keep plain dev defaults
- [x] One commit on `stack/host-env-gb-m4-compose`; check M4 boxes

---

## M5 — Dev-up + install wrapper + README

- [ ] Replace or extend `scripts/dev-up.sh` to resolve `gb`, HEV config, export mounts via gb dev deploy path (match Denarius: `gb "$PWD" deploy` with `GB_MODE=dev`)
- [ ] `install.sh` — thin wrapper: args/env → `generic-green-blue-deployment/bin/gb` (sibling or `/var/gb/…`) `--hev-config` when set, `"$ROOT" deploy`
- [ ] README **Production** section: `.greenblue.example.prod`, `gb deploy` / `revert`, vault inject; no runtime vault
- [ ] One commit on `stack/host-env-gb-m5-install`; check M5 boxes

---

## M6 — Closeout

- [ ] Full `composer test` + Infection on M3 scope; fix regressions
- [ ] Checklist footer: tip branch, commits, gate numbers
- [ ] All boxes above checked or explicitly deferred with reason
- [ ] One commit on `stack/host-env-gb-m6-closeout` if any doc-only fixes; else amend footer on M5 commit only if nothing to change

---

## Orchestrator log

| Milestone | Branch | Commit | Status | Notes |
| --- | --- | --- | --- | --- |
| M0 | `stack/host-env-gb-m0-docs` | `7cd875758901fb05967e1430bc393ccd7244930f` | ok | IDP-first ORK docs + host-env-gb plan; doc-only gates |
| M1 | `stack/host-env-gb-m1-catalog` | `f938f627ffc9184e08d56755e7f1a23ff8f66447` | ok | Vault catalog fragment + secrets.example; doc/json gates |
| M2 | `stack/host-env-gb-m2-greenblue` | `5b49d42ce7758fc0a9b314e7c6010526985010bc` | ok | Green-blue examples + gb hooks; shell/json gates |
| M3 | `stack/host-env-gb-m3-hostenv-php` | `23b2e49d68515f7d44d36cd65f93eaeee6945442` | ok | HostEnv inject bootstrap + phinx; log-tested skip/apply |
| M4 | `stack/host-env-gb-m4-compose` | `04fb5d9bcbfdda3a0c82c460f503baabe3578bc5` | ok | HEV `/run/secrets` mounts + optional legacy key binds |
| M5 | | | pending | |
| M6 | | | pending | |

**M0 gates:** doc-only milestone — `composer test` not required (markdown only).

**M1 gates:** doc/json only — `composer test` not required (no PHP changes).

**M2 gates:** shell/json only — `bash -n` + `shellcheck` on `scripts/gb-*.sh`, `json.tool` on `host-env/catalog.d/40-idp.json`; `composer test` not required (no PHP changes).

**M3 gates:** `composer test` OK (1037 tests); `src/` line coverage **86.58%** (6656/7688); Infection covered MSI **96%** on `src/Utility/Setup/HostEnv/`; log-tested `host_env_inject_skip` (debug) and `host_env_inject_applied` (info). Tip: `stack/host-env-gb-m3-hostenv-php` @ `23b2e49d68515f7d44d36cd65f93eaeee6945442`.

**M4 gates:** compose/env/doc only — `composer test` OK (1037 tests); no PHP changes (Infection N/A). Tip: `stack/host-env-gb-m4-compose` @ `04fb5d9bcbfdda3a0c82c460f503baabe3578bc5`.
