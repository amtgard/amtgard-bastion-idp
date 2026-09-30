#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export INTEG_COVERAGE=1
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-idp-worker}"
INTEG_JUNIT="$ROOT/build/integ-junit.xml"
COVERAGE_RAW="$ROOT/build/integ-coverage/raw"

KEEP=false
for arg in "$@"; do
    if [[ "$arg" == "--keep" ]]; then
        KEEP=true
    fi
done

if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running; integration coverage requires the integ stack." >&2
    exit 1
fi

rm -rf "$ROOT/build/integ-coverage"
mkdir -p "$COVERAGE_RAW"

fail=0
if ! ./scripts/integ-up.sh; then
    fail=1
fi

if [[ "$fail" -eq 0 ]]; then
    echo "==> Running integration tests with FPM PCOV collection..."
    mkdir -p "$ROOT/build"
    if ! composer integ -- --log-junit "$INTEG_JUNIT"; then
        fail=1
    fi
fi

if [[ "$KEEP" == false ]]; then
    echo "==> Stopping integ stack (worker SIGTERM flushes CLI PCOV)..."
    if ! ./scripts/integ-down.sh; then
        fail=1
    fi
else
    echo "==> Flushing jwt-worker PCOV (--keep)..."
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        -f docker/compose.worker.integ.yml \
        -f docker/compose.worker.integ-coverage.yml \
        stop jwt-worker >/dev/null 2>&1 || true
fi

if compgen -G "$COVERAGE_RAW/cov-*.json" > /dev/null; then
    echo "==> Merging PCOV fragments and writing HTML + text summary..."
    if ! php "$ROOT/scripts/integ-coverage-merge.php"; then
        fail=1
    fi
else
    echo "No PCOV fragments in ${COVERAGE_RAW}; FPM coverage may be misconfigured." >&2
    fail=1
fi

exit "$fail"
