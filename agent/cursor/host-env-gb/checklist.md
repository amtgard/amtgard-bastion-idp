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

- [ ] `host-env/catalog.d/40-idp.json` — profiles `idp-http`, `idp-worker`; inject-only; `inject_files`: `oauth-private.key`, `oauth-public.key` (no runtime, `socket: false`)
- [ ] `host-env/catalog.d/41-idp-linux-gid.json.example` — optional `gid: 33` for prod hosts
- [ ] `host-env/secrets.example/idp-http.env` and `idp-worker.env` (paths under `/run/secrets/files/…`)
- [ ] `host-env/secrets.example/files/` — placeholder or README for oauth keys + **dev-only** `apple.p8`
- [ ] `host-env/README.md` — copy fragment into vault `catalog.d`, seed local secrets, profile list
- [ ] One commit on `stack/host-env-gb-m1-catalog`; check M1 boxes

---

## M2 — Green-blue config + scripts

- [ ] `.greenblue.example.dev` — `GB_MODE=dev`, HEV local sync/render, `GB_HEV_PROFILES="idp-http idp-worker"`, `GB_HEV_MOUNT_MAP`, hooks, source `scripts/gb-greenblue-helpers.sh`
- [ ] `.greenblue.example.prod` — SSM sync/render on deploy, host `GB_STATE_DIR`, same profiles (no connect)
- [ ] `.gitignore` — `.greenblue`, `.gb/`
- [ ] `scripts/gb-greenblue-helpers.sh`, `gb-pre-hev-sync.sh` (install `40-idp.json`), `gb-hev-after-render.sh`, `gb-after-slot-up.sh` (jwt-worker tag)
- [ ] `.local/empty-secrets` pattern documented or mkdir in dev-up
- [ ] One commit on `stack/host-env-gb-m2-greenblue`; check M2 boxes

---

## M3 — PHP inject bootstrap

- [ ] `HostEnvBootstrap` + `InjectedSecretsLoader` under `src/Utility/Setup/HostEnv/` (Denarius pattern; map oauth basenames → `OAUTH_*` / `APPLE_KEY_FILE_PATH` for dev `.p8` only when file present)
- [ ] `config/host-env-bootstrap.php`; wire `config/bootstrap.php` after immutable `.env`
- [ ] `phinx.php` loads inject bootstrap (or equivalent) so migrate sees `DB_PASS` from mount
- [ ] Unit tests + log tests for skip vs apply paths
- [ ] One commit on `stack/host-env-gb-m3-hostenv-php`; check M3 boxes; record coverage + Infection in footer

---

## M4 — Compose + env wiring

- [ ] `docker/compose.prod.yml` — mount `${IDP_HEV_HTTP_SECRETS:-./.local/empty-secrets}:/run/secrets:ro`; `HOST_ENV_SECRETS_DIR=/run/secrets`; drop or optionalize `keys/` / `dev-keys/` binds when inject files suffice
- [ ] `docker/compose.worker.yml` — worker secrets mount + `HOST_ENV_SECRETS_DIR`
- [ ] `.env.example` — document non-secret vs vault; HEV comments; keep plain dev defaults
- [ ] One commit on `stack/host-env-gb-m4-compose`; check M4 boxes

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
| M1 | | | pending | |
| M2 | | | pending | |
| M3 | | | pending | |
| M4 | | | pending | |
| M5 | | | pending | |
| M6 | | | pending | |

**M0 gates:** doc-only milestone — `composer test` not required (markdown only).
