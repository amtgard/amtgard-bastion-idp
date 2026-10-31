#!/usr/bin/env bash
# Export IDP_HEV_* (and related) from .gb/runtime.env for docker compose.
# Values may contain spaces (e.g. Google Drive paths); do not use bare `source`.
set -euo pipefail

_gb_runtime_root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
_gb_runtime_file="${GB_RUNTIME_ENV_FILE:-${_gb_runtime_root}/.gb/runtime.env}"

if [[ ! -f "$_gb_runtime_file" ]]; then
    return 0 2>/dev/null || exit 0
fi

while IFS= read -r _line || [[ -n "$_line" ]]; do
    _line="${_line//$'\r'/}"
    if [[ -z "$_line" || "$_line" =~ ^[[:space:]]*# ]]; then
        continue
    fi
    if [[ ! "$_line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
        continue
    fi
    _key="${BASH_REMATCH[1]}"
    _val="${BASH_REMATCH[2]}"
    if [[ "$_val" =~ ^\"(.*)\"$ ]]; then
        _val="${BASH_REMATCH[1]}"
    elif [[ "$_val" =~ ^\'(.*)\'$ ]]; then
        _val="${BASH_REMATCH[1]}"
    fi
    export "${_key}=${_val}"
done < "$_gb_runtime_file"
