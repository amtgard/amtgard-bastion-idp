#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NETWORK="${NETWORK:-amtgard-idp-shared}"
INTEG_PROJECT="${INTEG_PROJECT:-amtgard-idp-integ}"
WEB_PROJECT="${WEB_PROJECT:-amtgard-idp}"
SESSIONS_PROJECT="${SESSIONS_PROJECT:-amtgard-idp-sessions}"
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-idp-worker}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-idp}"
DB_CONTAINER="${DB_CONTAINER:-amtgard-idp-db}"
INTEG_DB_CONTAINER="${INTEG_DB_CONTAINER:-amtgard-idp-db-integ}"

require_docker() {
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running." >&2
        exit 1
    fi
}

compose_integ_infra() {
    docker compose --project-directory "$ROOT" -p "$INTEG_PROJECT" \
        -f docker/compose.integ-infra.yml \
        "$@"
}

compose_sessions() {
    docker compose --project-directory "$ROOT" -p "$SESSIONS_PROJECT" \
        -f docker/compose.sessions.yml \
        "$@"
}

compose_web_dev() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

compose_web_integ() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        -f docker/compose.integ.yml \
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

wait_for_integ_db() {
    local attempt
    for attempt in $(seq 1 30); do
        if docker exec "$INTEG_DB_CONTAINER" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Timed out waiting for MariaDB in ${INTEG_DB_CONTAINER}" >&2
    return 1
}

wait_for_app() {
    local attempt
    for attempt in $(seq 1 30); do
        if curl -sf --max-time 2 "http://localhost:37080/version" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Timed out waiting for http://localhost:37080/version" >&2
    return 1
}

require_docker
ensure_shared_network

echo "==> Starting integ infra (${INTEG_PROJECT}: DB + session Redis)..."
compose_integ_infra up -d
wait_for_integ_db

echo "==> Starting sessions (${SESSIONS_PROJECT})..."
compose_sessions up -d

echo "==> Applying integ overlay on web stack (${WEB_PROJECT})..."
compose_web_integ up -d --build --remove-orphans --force-recreate amtgardidpapp

echo "==> Wiring php-fpm env for integ (DB_NAME, ENVIRONMENT, Apple)..."
APPLE_KEY_FILE_PATH="$(docker exec "$APP_CONTAINER" printenv APPLE_KEY_FILE_PATH || true)"
APPLE_LOGIN_ENABLED="$(docker exec "$APP_CONTAINER" printenv APPLE_LOGIN_ENABLED || true)"
docker exec "$APP_CONTAINER" bash -lc "
    POOL=/etc/php/8.4/fpm/pool.d/www.conf
    sed -i '/^env\[DB_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[ENVIRONMENT\]/d' \"\$POOL\"
    sed -i '/^env\[APPLE_KEY_FILE_PATH\]/d' \"\$POOL\"
    sed -i '/^env\[APPLE_LOGIN_ENABLED\]/d' \"\$POOL\"
    sed -i '/^env\[IDP_ORK_SHARED_SECRET\]/d' \"\$POOL\"
    sed -i '/^env\[ORK_BASE_URL\]/d' \"\$POOL\"
    sed -i '/^env\[MANAGEMENT_KEY\]/d' \"\$POOL\"
    sed -i '/^env\[LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS\]/d' \"\$POOL\"
    IDP_ORK_SHARED_SECRET=\"\$(printenv IDP_ORK_SHARED_SECRET || true)\"
    ORK_BASE_URL=\"\$(printenv ORK_BASE_URL || true)\"
    MANAGEMENT_KEY=\"\$(printenv MANAGEMENT_KEY || true)\"
    LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS=\"\$(printenv LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS || true)\"
    echo \"env[ENVIRONMENT] = \$ENVIRONMENT\" >> \"\$POOL\"
    echo \"env[DB_NAME] = \$DB_NAME\" >> \"\$POOL\"
    echo \"env[APPLE_KEY_FILE_PATH] = ${APPLE_KEY_FILE_PATH}\" >> \"\$POOL\"
    echo \"env[APPLE_LOGIN_ENABLED] = ${APPLE_LOGIN_ENABLED}\" >> \"\$POOL\"
    echo \"env[IDP_ORK_SHARED_SECRET] = \${IDP_ORK_SHARED_SECRET}\" >> \"\$POOL\"
    echo \"env[ORK_BASE_URL] = \${ORK_BASE_URL}\" >> \"\$POOL\"
    echo \"env[MANAGEMENT_KEY] = \${MANAGEMENT_KEY}\" >> \"\$POOL\"
    echo \"env[LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS] = \${LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS}\" >> \"\$POOL\"
    service php8.4-fpm restart
"

echo "==> Ensuring integ schema idp_integ..."
docker exec "$DB_CONTAINER" mariadb -uroot -proot -e \
    "CREATE DATABASE IF NOT EXISTS idp_integ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     GRANT ALL PRIVILEGES ON idp_integ.* TO 'idp'@'%';
     FLUSH PRIVILEGES;"

echo "==> Migrating integ schema..."
docker exec -e MIGRATE_DB_NAME=idp_integ "$APP_CONTAINER" bash -lc \
    'cd /var/www/idp.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate'

echo "==> Seeding integ fixtures..."
docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/idp.amtgard.com && php tests/Integration/seed.php'

echo "==> Starting jwt-worker (${WORKER_PROJECT})..."
compose_worker up -d --build

echo "==> Waiting for app health..."
wait_for_app

echo "Integ stack is up (ENVIRONMENT=DEV_INTEG, DB_NAME=idp_integ)."
