#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WEB_PROJECT="${WEB_PROJECT:-amtgard-idp}"

resolve_gb_bin() {
    if [[ -n "${GREENBLUE_BIN:-}" && -x "$GREENBLUE_BIN" ]]; then
        echo "$GREENBLUE_BIN"
        return 0
    fi
    if command -v gb >/dev/null 2>&1; then
        command -v gb
        return 0
    fi
    local candidate
    for candidate in \
        /var/gb/generic-green-blue-deployment/bin/gb \
        "${ROOT}/../generic-green-blue-deployment/bin/gb"; do
        if [[ -x "$candidate" ]]; then
            echo "$candidate"
            return 0
        fi
    done
    echo "dev-up.sh: generic-green-blue 'gb' not found." >&2
    echo "  Set GREENBLUE_BIN, add gb to PATH, or clone generic-green-blue-deployment beside this repo." >&2
    exit 1
}

ensure_greenblue() {
    if [[ -f "${ROOT}/.greenblue" ]]; then
        return 0
    fi
    echo "dev-up.sh: missing ${ROOT}/.greenblue (copy from .greenblue.example.dev)." >&2
    exit 1
}

ensure_empty_secrets() {
    mkdir -p "${ROOT}/.local/empty-secrets"
}

maybe_catalog_sync() {
    if [[ "${DEV_UP_SKIP_CATALOG_SYNC:-0}" == "1" ]]; then
        return 0
    fi
    if [[ ! -x "${ROOT}/scripts/gb-pre-hev-sync.sh" ]]; then
        return 0
    fi
    GB_ROOT="$ROOT" bash "${ROOT}/scripts/gb-pre-hev-sync.sh"
}

run_dev_up() {
    local gb_bin hev_cfg
    local -a gb_argv=()

    gb_bin="$(resolve_gb_bin)"
    ensure_greenblue
    ensure_empty_secrets
    maybe_catalog_sync

    hev_cfg="${GB_HEV_CONFIG:-${HOST_ENV_VAULT_CONFIG:-}}"
    if [[ -n "$hev_cfg" ]]; then
        if [[ ! -f "$hev_cfg" ]]; then
            echo "dev-up.sh: HEV config not found: ${hev_cfg}" >&2
            exit 1
        fi
        gb_argv+=(--hev-config "$hev_cfg")
    fi
    gb_argv+=("$ROOT" deploy)
    exec "$gb_bin" "${gb_argv[@]}"
}

run_test() {
    local -a env_args=()
    if [[ -f "${ROOT}/.gb/runtime.env" ]]; then
        env_args=(--env-file "${ROOT}/.gb/runtime.env")
    fi
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        "${env_args[@]}" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        --profile test run --rm test
}

case "${1:-up}" in
    up)
        run_dev_up
        ;;
    test)
        run_test
        ;;
    *)
        echo "Usage: $0 [up|test]" >&2
        exit 1
        ;;
esac
