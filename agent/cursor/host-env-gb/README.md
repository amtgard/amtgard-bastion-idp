# host-env-vault + generic-green-blue (IDP)

Deploy-time **inject-only** secrets for production and local dev, following **amtgard-denarius** and **generic-green-blue-deployment** (2026-10).

## Product / linking (orthogonal)

**ORK account linking always starts on the IDP** (signed-in user on `/resources/profile`, Flow A v2 Email Code). Legacy ORK `/auth/connect` handoff remains compat-only. See [../ork-link-possession/idp-contract.md](../ork-link-possession/idp-contract.md).

## Scope

| In | Out |
| --- | --- |
| `.greenblue.example.dev` / `.greenblue.example.prod`; local `.greenblue` (gitignored) | `host-env-vault-php` / runtime sockets |
| Profiles **`idp-http`**, **`idp-worker`** (`secrets.env` + `files/` for OAuth PEMs) | Denarius-style `*-connect` profile |
| `GB_HEV_MOUNT_MAP`, `.gb/runtime.env`, `gb` deploy | Secrets in git |
| Thin **`install.sh`** → `gb deploy` | Deleting `install.sh` |
| **`apple.p8`** in dev secrets.example only | Prod Apple inject unless enabled later |
| HostEnvBootstrap after project `.env` | PHPUnit/integ vault mounts |

## References

- Denarius: `.greenblue.example.*`, `host-env/`, `scripts/gb-*.sh`, `host-env/README.md`
- Toolkit: `generic-green-blue-deployment/README.md`
- Vault seeds: `../host-env-vault/.../dev-shared/secrets/idp-*.env` (sibling checkout)

## Checklist

Work queue: [checklist.md](./checklist.md). Orchestrator runs milestones **serially**; each milestone is one stacked branch and one commit.
