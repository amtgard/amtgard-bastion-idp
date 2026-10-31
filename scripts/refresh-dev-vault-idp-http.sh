#!/usr/bin/env bash
# Copy dev OAuth PEMs into host-env-vault, merge non-empty repo .env into idp-http.env
# (empty inject values break host-env-vault sync), sync, render idp-http, recreate dev web slot.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=scripts/gb-greenblue-helpers.sh disable=SC1091
source "$ROOT/scripts/gb-greenblue-helpers.sh"

vault_root="${GB_HEV_VAULT_ROOT:-}"
if [[ -z "$vault_root" && -n "${GB_HEV_CONFIG:-}" && -f "$GB_HEV_CONFIG" ]]; then
    vault_root="$(cd "$(dirname "$GB_HEV_CONFIG")/.." && pwd)"
fi
if [[ -z "$vault_root" || ! -d "$vault_root/dev-shared/secrets" ]]; then
    vault_root="$(cd "$ROOT/../host-env-vault" && pwd)"
fi

secrets_dir="$vault_root/dev-shared/secrets"
files_dir="$secrets_dir/files"
idp_http_env="$secrets_dir/idp-http.env"
dotenv="$ROOT/.env"

if [[ ! -f "$dotenv" ]]; then
    echo "Missing $dotenv" >&2
    exit 1
fi
if [[ ! -f "$idp_http_env" ]]; then
    echo "Missing $idp_http_env — seed from host-env/secrets.example/idp-http.env first." >&2
    exit 1
fi
if [[ ! -f "$ROOT/dev-keys/private.key" || ! -f "$ROOT/dev-keys/public.key" ]]; then
    echo "Missing dev-keys/private.key or public.key under $ROOT" >&2
    exit 1
fi

mkdir -p "$files_dir"
cp "$ROOT/dev-keys/private.key" "$files_dir/oauth-private.key"
cp "$ROOT/dev-keys/public.key" "$files_dir/oauth-public.key"
chmod 600 "$files_dir/oauth-private.key"
chmod 644 "$files_dir/oauth-public.key"

python3 - "$dotenv" "$idp_http_env" <<'PY'
import re
import sys

dotenv_path, vault_env_path = sys.argv[1:3]
merge_keys = [
    "APP_SECRET", "DB_PASS", "JWT_KEY", "AUTH_SERVER_DEFUSE_KEY", "OAUTH_ENCRYPTION_KEY",
    "IDP_ORK_SHARED_SECRET", "MANAGEMENT_KEY", "MAILBOX_CODE_PEPPER",
    "GOOGLE_CLIENT_SECRET", "FACEBOOK_CLIENT_SECRET", "DISCORD_CLIENT_SECRET",
    "SENDGRID_API_KEY", "AMAZON_SES_PASSWORD",
]
path_keys = {
    "OAUTH_PRIVATE_KEY": "/run/secrets/files/oauth-private.key",
    "OAUTH_PUBLIC_KEY": "/run/secrets/files/oauth-public.key",
    "APPLE_KEY_FILE_PATH": "/run/secrets/files/apple.p8",
}

def parse_dotenv(path):
    out = {}
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            v = v.strip().strip('"').strip("'")
            out[k.strip()] = v
    return out

repo = parse_dotenv(dotenv_path)
lines = open(vault_env_path, encoding="utf-8").read().splitlines()
out_lines = []
seen = set()
key_re = re.compile(r"^([A-Z0-9_]+)=(.*)$")

for line in lines:
    if line.strip().startswith("#") or not line.strip():
        out_lines.append(line)
        continue
    m = key_re.match(line.strip())
    if not m:
        out_lines.append(line)
        continue
    key, _val = m.group(1), m.group(2)
    seen.add(key)
    if key in path_keys:
        out_lines.append(f"{key}={path_keys[key]}")
    elif key in merge_keys:
        val = repo.get(key, "")
        if not val:
            val = _val.strip() if _val.strip() else "local-dev-unset"
        out_lines.append(f"{key}={val}")
    else:
        out_lines.append(line)

for key, val in path_keys.items():
    if key not in seen:
        out_lines.append(f"{key}={val}")

with open(vault_env_path, "w", encoding="utf-8") as f:
    f.write("\n".join(out_lines) + "\n")
PY

bash "$ROOT/scripts/gb-pre-hev-sync.sh"

hev_bin="${GB_HEV_BIN:-}"
if [[ -z "$hev_bin" ]]; then
    if [[ -n "${GB_HEV_REPO:-}" && -x "${GB_HEV_REPO}/target/release/host-env-vault" ]]; then
        hev_bin="${GB_HEV_REPO}/target/release/host-env-vault"
    elif [[ -x "$vault_root/target/release/host-env-vault" ]]; then
        hev_bin="$vault_root/target/release/host-env-vault"
    elif command -v host-env-vault >/dev/null 2>&1; then
        hev_bin="host-env-vault"
    else
        echo "host-env-vault binary not found" >&2
        exit 1
    fi
fi

hev_config="${GB_HEV_CONFIG:-$vault_root/dev-shared/host-config.toml}"
if [[ ! -f "$hev_config" ]]; then
    echo "Missing host-env-vault config: $hev_config" >&2
    exit 1
fi

cfg_rel="dev-shared/host-config.toml"
if [[ "$hev_config" == "$vault_root/"* ]]; then
    cfg_rel="${hev_config#"$vault_root/"}"
fi

echo "==> host-env-vault sync (local)..."
(
    cd "$vault_root"
    "$hev_bin" sync --source local --local-secrets-dir dev-shared/secrets \
        --identity dev-shared/kek.age --state-dir dev-shared/state --config "$cfg_rel"
) || {
    if [[ "${GB_HEV_SYNC_STRICT:-1}" == "0" ]]; then
        echo "WARN: full catalog sync reported failures (GB_HEV_SYNC_STRICT=0); continuing to render idp-http..." >&2
    else
        exit 1
    fi
}

render_dest="$vault_root/dev-shared/run/idp-http/current"
parent="$(dirname "$render_dest")"
rm -rf "$render_dest"
mkdir -p "$render_dest"

echo "==> host-env-vault render idp-http -> $render_dest"
(
    cd "$vault_root"
    "$hev_bin" render --profile idp-http --dest "$render_dest" \
        --identity dev-shared/kek.age --state-dir dev-shared/state --config "$cfg_rel"
)

# shellcheck source=scripts/source-gb-runtime-env.sh disable=SC1091
source "$ROOT/scripts/source-gb-runtime-env.sh" "$ROOT"
runtime_file="${GB_RUNTIME_ENV_FILE:-$ROOT/.gb/runtime.env}"
mkdir -p "$(dirname "$runtime_file")"
{
    printf 'IDP_HEV_HTTP_SECRETS="%s"\n' "$render_dest"
    if [[ -d "$vault_root/dev-shared/run/idp-worker/current" ]]; then
        printf 'IDP_HEV_WORKER_SECRETS="%s"\n' "$vault_root/dev-shared/run/idp-worker/current"
    fi
} >"$runtime_file"

echo "==> Recreate dev web container with vault mount..."
"$ROOT/scripts/integ-down.sh"

echo "OAuth inject refreshed. PEM head:"
head -1 "$render_dest/files/oauth-private.key"
