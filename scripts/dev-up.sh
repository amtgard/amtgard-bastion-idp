#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NETWORK="${NETWORK:-amtgard-idp-shared}"
WEB_PROJECT="${WEB_PROJECT:-amtgard-idp}"
SESSIONS_PROJECT="${SESSIONS_PROJECT:-amtgard-idp-sessions}"
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-idp-worker}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-idp}"
WORKER_CONTAINER="${WORKER_CONTAINER:-amtgard-idp-jwt-worker}"

compose_sessions() {
    docker compose --project-directory "$ROOT" -p "$SESSIONS_PROJECT" \
        -f docker/compose.sessions.yml \
        "$@"
}

compose_web() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

compose_worker() {
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        "$@"
}

ensure_shared_network() {
    if docker network inspect "$NETWORK" >/dev/null 2>&1; then
        return
    fi
    echo "==> Creating Docker network ${NETWORK}..."
    docker network create "$NETWORK"
}

stop_app_worker() {
    if ! docker ps --format '{{.Names}}' | grep -Fxq "$APP_CONTAINER"; then
        return
    fi
    docker exec "$APP_CONTAINER" bash -lc \
        'pkill -f "jwt-pvh-worker.php" >/dev/null 2>&1 || true' || true
}

reclaim_worker_container() {
    if ! docker inspect "$WORKER_CONTAINER" >/dev/null 2>&1; then
        return
    fi
    local project
    project="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$WORKER_CONTAINER" 2>/dev/null || true)"
    if [[ "$project" != "$WORKER_PROJECT" ]]; then
        echo "==> Removing leftover ${WORKER_CONTAINER} from project ${project:-unknown}..."
        docker rm -f "$WORKER_CONTAINER" >/dev/null 2>&1 || true
    fi
}

up() {
    ensure_shared_network
    echo "==> Starting sessions (${SESSIONS_PROJECT})..."
    compose_sessions up -d
    echo "==> Starting web + db (${WEB_PROJECT})..."
    compose_web up -d --build --remove-orphans
    stop_app_worker
    reclaim_worker_container
    echo "==> Starting jwt-worker (${WORKER_PROJECT})..."
    compose_worker up -d --build
}

case "${1:-up}" in
    up)
        up
        ;;
    test)
        compose_web --profile test run --rm test
        ;;
    *)
        echo "Usage: $0 [up|test]" >&2
        exit 1
        ;;
esac
