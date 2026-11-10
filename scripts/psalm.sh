#!/usr/bin/env bash
# Run Psalm; apply a local (gitignored) baseline when psalm-baseline.xml exists.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

args=(--no-cache)
if [[ -f psalm-baseline.xml ]]; then
    args+=(--use-baseline=psalm-baseline.xml)
fi

exec vendor/bin/psalm "${args[@]}" "$@"
