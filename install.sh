#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

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
    echo "install.sh: generic-green-blue 'gb' not found." >&2
    echo "  Set GREENBLUE_BIN, add gb to PATH, or install under /var/gb/generic-green-blue-deployment." >&2
    exit 1
}

is_commit_sha() {
    [[ "$1" =~ ^[0-9a-fA-F]{7,40}$ ]]
}

export_gb_install_env() {
    export GB_INSTALL_SKIP_GIT_PULL="${GB_INSTALL_SKIP_GIT_PULL:-${INSTALL_SKIP_GIT_PULL:-0}}"
    export GB_INSTALL_SKIP_CHOWN="${GB_INSTALL_SKIP_CHOWN:-${INSTALL_SKIP_CHOWN:-0}}"
    export GB_INSTALL_SKIP_HOST_NGINX="${GB_INSTALL_SKIP_HOST_NGINX:-${INSTALL_SKIP_HOST_NGINX:-0}}"
    export GB_INSTALL_REBUILD_SESSIONS="${GB_INSTALL_REBUILD_SESSIONS:-${INSTALL_REBUILD_SESSIONS:-0}}"
    export GB_INSTALL_RESET_SESSIONS="${GB_INSTALL_RESET_SESSIONS:-${INSTALL_RESET_SESSIONS:-0}}"
    export GB_INSTALL_REBUILD_WORKER="${GB_INSTALL_REBUILD_WORKER:-${INSTALL_REBUILD_WORKER:-0}}"
    export GB_INSTALL_GIT_REF="${GB_INSTALL_GIT_REF:-${INSTALL_GIT_REF:-}}"
    export GB_GIT_BRANCH="${GB_GIT_BRANCH:-${GIT_BRANCH:-main}}"
}

usage() {
    cat <<EOF
Usage: $(basename "$0") [commit-sha]

Production blue-green deploy via generic-green-blue-deployment (gb).
Configure the host once: cp .greenblue.example.prod .greenblue (not committed).

With no argument, gb fetches/pulls \${GB_GIT_BRANCH:-main} and deploys the inactive slot.
With a 7–40 character hex SHA, deploy that commit (detached HEAD) — roll back a bad release.

  sudo ./install.sh
  sudo ./install.sh 9f3c1ab
  sudo INSTALL_GIT_REF=9f3c1ab ./install.sh

Fast nginx rollback without rebuilding a slot:

  sudo gb "\$PWD" revert
  # or: /var/gb/generic-green-blue-deployment/bin/gb /var/www/idp.amtgard.com revert

Optional host-env-vault: set GB_HEV_CONFIG or HOST_ENV_VAULT_CONFIG, or pass via gb --hev-config.

Install flags (INSTALL_* or GB_*): SKIP_GIT_PULL, SKIP_CHOWN, SKIP_HOST_NGINX,
RESET_SESSIONS, REBUILD_SESSIONS, REBUILD_WORKER (auto on pinned SHA deploy).

Pinned deploys recreate the jwt-worker. Phinx migrate is not rolled back.
EOF
}

main() {
    if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
        usage
        exit 0
    fi

    if [[ $# -gt 1 ]]; then
        usage >&2
        exit 1
    fi

    if [[ $# -eq 1 ]]; then
        if ! is_commit_sha "$1"; then
            echo "install.sh: argument must be a 7–40 character hex commit SHA." >&2
            usage >&2
            exit 1
        fi
        export INSTALL_GIT_REF="$1"
        export GB_INSTALL_GIT_REF="$1"
    fi

    export_gb_install_env

    if [[ -n "$GB_INSTALL_GIT_REF" && "$GB_INSTALL_REBUILD_WORKER" == "0" ]]; then
        export GB_INSTALL_REBUILD_WORKER=1
        export INSTALL_REBUILD_WORKER=1
    fi

    if [[ ! -f "${ROOT}/.greenblue" ]]; then
        echo "install.sh: missing ${ROOT}/.greenblue (copy from .greenblue.example.prod)." >&2
        exit 1
    fi

    local gb_bin hev_cfg
    local -a gb_argv=()

    gb_bin="$(resolve_gb_bin)"
    hev_cfg="${GB_HEV_CONFIG:-${HOST_ENV_VAULT_CONFIG:-}}"
    if [[ -n "$hev_cfg" ]]; then
        if [[ ! -f "$hev_cfg" ]]; then
            echo "install.sh: HEV config not found: ${hev_cfg}" >&2
            exit 1
        fi
        gb_argv+=(--hev-config "$hev_cfg")
    fi

    gb_argv+=("$ROOT" deploy)
    exec "$gb_bin" "${gb_argv[@]}"
}

main "$@"
