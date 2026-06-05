#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

GIT_BRANCH="${GIT_BRANCH:-main}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
COMPOSER_FLAGS="${COMPOSER_FLAGS:---no-dev --optimize-autoloader}"
PHINX_ENV="${PHINX_ENV:-production}"

run_as_web_user() {
    if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
        sudo -u "$WEB_USER" -- "$@"
    else
        "$@"
    fi
}

chown_app() {
    if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
        chown -R "${WEB_USER}:${WEB_GROUP}" .
        return
    fi

    if command -v sudo >/dev/null 2>&1; then
        sudo chown -R "${WEB_USER}:${WEB_GROUP}" .
        return
    fi

    echo "install.sh: chown requires root or sudo." >&2
    exit 1
}

echo "==> Pulling latest from ${GIT_BRANCH}..."
git fetch origin "$GIT_BRANCH"
git checkout "$GIT_BRANCH"
git pull --ff-only origin "$GIT_BRANCH"

echo "==> Setting ownership to ${WEB_USER}:${WEB_GROUP}..."
chown_app

echo "==> Installing Composer dependencies..."
read -r -a composer_flags <<< "$COMPOSER_FLAGS"
run_as_web_user composer install "${composer_flags[@]}"

echo "==> Running Phinx migrations (${PHINX_ENV})..."
run_as_web_user vendor/bin/phinx migrate -e "$PHINX_ENV"

echo "==> Install complete."
