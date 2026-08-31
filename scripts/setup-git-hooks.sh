#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "setup-git-hooks.sh: not a git repository." >&2
    exit 1
fi

chmod +x .githooks/post-commit
git config core.hooksPath .githooks

echo "Git hooks enabled (core.hooksPath=.githooks)."
echo "Commits will update VERSION and version.json automatically."
