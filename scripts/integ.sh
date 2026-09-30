#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

KEEP=false
for arg in "$@"; do
    if [[ "$arg" == "--keep" ]]; then
        KEEP=true
    fi
done

if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running; integration harness requires the dev stack." >&2
    exit 1
fi

fail=0
if ! ./scripts/integ-up.sh; then
    fail=1
fi

if [[ "$fail" -eq 0 ]]; then
    echo "==> Running integration tests (testdox)..."
    if ! composer integ; then
        fail=1
    fi
fi

if [[ "$KEEP" == false ]]; then
    if ! ./scripts/integ-down.sh; then
        fail=1
    fi
fi

exit "$fail"
