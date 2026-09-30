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
INTEG_DB_CONTAINER="${INTEG_DB_CONTAINER:-amtgard-idp-db-integ}"
INTEG_SESSIONS_CONTAINER="${INTEG_SESSIONS_CONTAINER:-amtgard-idp-sessions-integ}"

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
    local coverage_args=()
    if [[ "${INTEG_COVERAGE:-}" == "1" ]]; then
        coverage_args=(-f docker/compose.integ-coverage.yml)
    fi
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        -f docker/compose.integ.yml \
        "${coverage_args[@]}" \
        "$@"
}

compose_worker() {
    local coverage_args=()
    if [[ "${INTEG_COVERAGE:-}" == "1" ]]; then
        coverage_args=(-f docker/compose.worker.integ-coverage.yml)
    fi
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        -f docker/compose.worker.integ.yml \
        "${coverage_args[@]}" \
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

echo "==> Wiring php-fpm env for integ (DB/Redis hosts, ENVIRONMENT, Apple)..."
APPLE_KEY_FILE_PATH="$(docker exec "$APP_CONTAINER" printenv APPLE_KEY_FILE_PATH || true)"
APPLE_LOGIN_ENABLED="$(docker exec "$APP_CONTAINER" printenv APPLE_LOGIN_ENABLED || true)"
DB_HOST="$(docker exec "$APP_CONTAINER" printenv DB_HOST || true)"
SESSION_REDIS_HOST="$(docker exec "$APP_CONTAINER" printenv SESSION_REDIS_HOST || true)"
docker exec "$APP_CONTAINER" bash -lc "
    POOL=/etc/php/8.4/fpm/pool.d/www.conf
    sed -i '/^env\[DB_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[DB_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[SESSION_REDIS_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[ENVIRONMENT\]/d' \"\$POOL\"
    sed -i '/^env\[APPLE_KEY_FILE_PATH\]/d' \"\$POOL\"
    sed -i '/^env\[APPLE_LOGIN_ENABLED\]/d' \"\$POOL\"
    sed -i '/^env\[IDP_ORK_SHARED_SECRET\]/d' \"\$POOL\"
    sed -i '/^env\[ORK_BASE_URL\]/d' \"\$POOL\"
    sed -i '/^env\[MANAGEMENT_KEY\]/d' \"\$POOL\"
    sed -i '/^env\[LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS\]/d' \"\$POOL\"
    sed -i '/^env\[MAILBOX_CODE_PEPPER\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_PUBSUB_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_PUBSUB_PORT\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_PUBSUB_DB\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_PUBSUB_QUEUE_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_PVH_QUEUE_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[INTEG_COVERAGE_ENABLED\]/d' \"\$POOL\"
    sed -i '/^env\[INTEG_COVERAGE_RAW_DIR\]/d' \"\$POOL\"
    IDP_ORK_SHARED_SECRET=\"\$(printenv IDP_ORK_SHARED_SECRET || true)\"
    ORK_BASE_URL=\"\$(printenv ORK_BASE_URL || true)\"
    MANAGEMENT_KEY=\"\$(printenv MANAGEMENT_KEY || true)\"
    LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS=\"\$(printenv LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS || true)\"
    MAILBOX_CODE_PEPPER=\"\$(printenv MAILBOX_CODE_PEPPER || true)\"
    echo \"env[ENVIRONMENT] = \$ENVIRONMENT\" >> \"\$POOL\"
    echo \"env[DB_HOST] = ${DB_HOST}\" >> \"\$POOL\"
    echo \"env[DB_NAME] = \$DB_NAME\" >> \"\$POOL\"
    echo \"env[SESSION_REDIS_HOST] = ${SESSION_REDIS_HOST}\" >> \"\$POOL\"
    REDIS_PUBSUB_HOST=\"\$(printenv REDIS_PUBSUB_HOST || true)\"
    REDIS_PUBSUB_PORT=\"\$(printenv REDIS_PUBSUB_PORT || true)\"
    REDIS_PUBSUB_DB=\"\$(printenv REDIS_PUBSUB_DB || true)\"
    REDIS_PUBSUB_QUEUE_NAME=\"\$(printenv REDIS_PUBSUB_QUEUE_NAME || true)\"
    REDIS_PVH_QUEUE_NAME=\"\$(printenv REDIS_PVH_QUEUE_NAME || true)\"
    echo \"env[REDIS_PUBSUB_HOST] = \${REDIS_PUBSUB_HOST}\" >> \"\$POOL\"
    echo \"env[REDIS_PUBSUB_PORT] = \${REDIS_PUBSUB_PORT}\" >> \"\$POOL\"
    echo \"env[REDIS_PUBSUB_DB] = \${REDIS_PUBSUB_DB}\" >> \"\$POOL\"
    echo \"env[REDIS_PUBSUB_QUEUE_NAME] = \${REDIS_PUBSUB_QUEUE_NAME}\" >> \"\$POOL\"
    echo \"env[REDIS_PVH_QUEUE_NAME] = \${REDIS_PVH_QUEUE_NAME}\" >> \"\$POOL\"
    echo \"env[APPLE_KEY_FILE_PATH] = ${APPLE_KEY_FILE_PATH}\" >> \"\$POOL\"
    echo \"env[APPLE_LOGIN_ENABLED] = ${APPLE_LOGIN_ENABLED}\" >> \"\$POOL\"
    echo \"env[IDP_ORK_SHARED_SECRET] = \${IDP_ORK_SHARED_SECRET}\" >> \"\$POOL\"
    echo \"env[ORK_BASE_URL] = \${ORK_BASE_URL}\" >> \"\$POOL\"
    echo \"env[MANAGEMENT_KEY] = \${MANAGEMENT_KEY}\" >> \"\$POOL\"
    echo \"env[LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS] = \${LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS}\" >> \"\$POOL\"
    echo \"env[MAILBOX_CODE_PEPPER] = \${MAILBOX_CODE_PEPPER}\" >> \"\$POOL\"
    INTEG_COVERAGE_ENABLED=\"\$(printenv INTEG_COVERAGE_ENABLED || true)\"
    INTEG_COVERAGE_RAW_DIR=\"\$(printenv INTEG_COVERAGE_RAW_DIR || true)\"
    if [[ -n \"\${INTEG_COVERAGE_ENABLED}\" ]]; then
        echo \"env[INTEG_COVERAGE_ENABLED] = \${INTEG_COVERAGE_ENABLED}\" >> \"\$POOL\"
    fi
    if [[ -n \"\${INTEG_COVERAGE_RAW_DIR}\" ]]; then
        echo \"env[INTEG_COVERAGE_RAW_DIR] = \${INTEG_COVERAGE_RAW_DIR}\" >> \"\$POOL\"
    fi
    service php8.4-fpm restart
"

echo "==> Flushing integ session Redis..."
SESSION_REDIS_DB="$(docker exec "$APP_CONTAINER" printenv SESSION_REDIS_DB || echo 1)"
docker exec "$INTEG_SESSIONS_CONTAINER" redis-cli -n "$SESSION_REDIS_DB" FLUSHDB

echo "==> Flushing integ pub/sub Redis (PVH cache + queues)..."
PUBSUB_REDIS_DB="$(docker exec "$APP_CONTAINER" printenv REDIS_PUBSUB_DB || echo 0)"
docker exec "$INTEG_SESSIONS_CONTAINER" redis-cli -n "$PUBSUB_REDIS_DB" FLUSHDB

echo "==> Migrating integ database (schema idp on ${INTEG_DB_CONTAINER})..."
docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/idp.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate'

echo "==> Seeding integ fixtures..."
docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/idp.amtgard.com && php tests/Integration/seed.php'

echo "==> Starting jwt-worker (${WORKER_PROJECT})..."
compose_worker up -d --build

echo "==> Waiting for app health..."
wait_for_app

echo "Integ stack is up (ENVIRONMENT=DEV_INTEG, DB_HOST=${DB_HOST}, DB_NAME=idp, SESSION_REDIS_HOST=${SESSION_REDIS_HOST})."
