#!/usr/bin/env bash
# GB_HOOK_AFTER_SLOT_HEALTH — verify Docsify shell and markdown after gb health on /.
set -euo pipefail

slot="${1:?slot required (blue|green)}"

case "$slot" in
    blue) port="${GB_BLUE_PORT:-37080}" ;;
    green) port="${GB_GREEN_PORT:-37081}" ;;
    *) exit 1 ;;
esac

base="http://127.0.0.1:${port}"

check() {
    local path="$1"
    local needle="$2"
    local body
    body="$(curl -fsS --max-time 5 "${base}${path}")"
    if [[ "$body" != *"$needle"* ]]; then
        echo "developer docs health failed: ${path} missing expected content" >&2
        exit 1
    fi
}

check "/docs" "Amtgard IDP - Developer Documentation"
check "/docs/readme.md" "# Amtgard Identity Provider Integration Guide"
