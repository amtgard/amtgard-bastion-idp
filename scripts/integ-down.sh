#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WEB_PROJECT="${WEB_PROJECT:-amtgard-idp}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-idp}"

require_docker() {
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running." >&2
        exit 1
    fi
}

compose_web_dev() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

require_docker

if ! docker inspect "$APP_CONTAINER" >/dev/null 2>&1; then
    echo "App container ${APP_CONTAINER} is not present; nothing to restore."
    exit 0
fi

echo "==> Restoring normal dev overlay (ENVIRONMENT=DEV, schema idp from .env)..."
compose_web_dev up -d --force-recreate amtgardidpapp

environment="$(docker exec "$APP_CONTAINER" printenv ENVIRONMENT || true)"
if [[ "$environment" != "DEV" ]]; then
    echo "Expected ENVIRONMENT=DEV after integ-down, got: ${environment:-<unset>}" >&2
    exit 1
fi

docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/idp.amtgard.com && php tests/Integration/verify_dev_http_client.php'

echo "Dev stack restored."
