#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

GIT_BRANCH="${GIT_BRANCH:-main}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
CONTAINER_APP_DIR="${CONTAINER_APP_DIR:-/var/www/idp.amtgard.com}"
COMPOSER_FLAGS="${COMPOSER_FLAGS:---no-dev --optimize-autoloader}"
PHINX_ENV="${PHINX_ENV:-production}"

LEGACY_CONTAINER="${LEGACY_CONTAINER:-amtgard-idp}"
BLUE_CONTAINER="${BLUE_CONTAINER:-amtgard-idp-blue}"
GREEN_CONTAINER="${GREEN_CONTAINER:-amtgard-idp-green}"
BLUE_PORT="${BLUE_PORT:-37080}"
GREEN_PORT="${GREEN_PORT:-37081}"

STATE_DIR="${DEPLOY_STATE_DIR:-/var/lib/amtgard-idp}"
STATE_FILE="${DEPLOY_STATE_FILE:-${STATE_DIR}/active}"
PREVIOUS_STATE_FILE="${STATE_DIR}/previous"

NGINX_SITE_NAME="${NGINX_SITE_NAME:-idp.amtgard.com}"
NGINX_SITES_AVAILABLE="${NGINX_SITES_AVAILABLE:-/etc/nginx/sites-available/${NGINX_SITE_NAME}}"
NGINX_SITES_ENABLED="${NGINX_SITES_ENABLED:-/etc/nginx/sites-enabled/${NGINX_SITE_NAME}}"

HEALTH_PATH="${HEALTH_PATH:-/}"
HEALTH_RETRIES="${HEALTH_RETRIES:-30}"
HEALTH_INTERVAL="${HEALTH_INTERVAL:-2}"

run_priv() {
    if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$@"
    else
        echo "install.sh: root or sudo required." >&2
        exit 1
    fi
}

chown_app() {
    run_priv chown -R "${WEB_USER}:${WEB_GROUP}" .
}

pull_code() {
    echo "==> Pulling latest from ${GIT_BRANCH}..."
    git fetch origin "$GIT_BRANCH"
    git checkout "$GIT_BRANCH"
    git pull --ff-only origin "$GIT_BRANCH"
    chown_app
}

uses_blue_green() {
    [[ -f "${ROOT}/docker/compose.prod.yml" ]]
}

slot_port() {
    case "$1" in
        blue) echo "$BLUE_PORT" ;;
        green) echo "$GREEN_PORT" ;;
    esac
}

slot_container() {
    case "$1" in
        blue) echo "$BLUE_CONTAINER" ;;
        green) echo "$GREEN_CONTAINER" ;;
    esac
}

inactive_slot() {
    if [[ "$1" == "blue" ]]; then echo "green"; else echo "blue"; fi
}

read_active_slot() {
    if [[ -f "$STATE_FILE" ]]; then
        local slot
        slot="$(tr -d '[:space:]' < "$STATE_FILE")"
        if [[ "$slot" == "blue" || "$slot" == "green" ]]; then
            echo "$slot"
            return
        fi
    fi
    echo ""
}

write_active_slot() {
    run_priv mkdir -p "$STATE_DIR"
    printf '%s\n' "$1" | run_priv tee "$STATE_FILE" >/dev/null
}

write_previous_slot() {
    run_priv mkdir -p "$STATE_DIR"
    printf '%s\n' "$1" | run_priv tee "$PREVIOUS_STATE_FILE" >/dev/null
}

compose_for_slot() {
    local slot="$1"
    shift
    docker compose --project-directory "$ROOT" -p "amtgard-idp-${slot}" \
        -f docker/compose.prod.yml \
        -f "docker/compose.${slot}.yml" \
        "$@"
}

container_running() {
    docker ps --format '{{.Names}}' | grep -Fxq "$1"
}

run_in_container() {
    docker exec -u "$WEB_USER" -w "$CONTAINER_APP_DIR" "$1" "${@:2}"
}

install_app_in_container() {
    local container="$1"
    echo "==> Installing Composer dependencies in ${container}..."
    read -r -a composer_flags <<< "$COMPOSER_FLAGS"
    run_in_container "$container" composer install "${composer_flags[@]}"

    echo "==> Running Phinx migrations (${PHINX_ENV}) in ${container}..."
    run_in_container "$container" vendor/bin/phinx migrate -e "$PHINX_ENV"
}

health_check_port() {
    local label="$1"
    local port="$2"
    local url="http://127.0.0.1:${port}${HEALTH_PATH}"

    echo "==> Health check ${label} at ${url}..."
    for ((i = 1; i <= HEALTH_RETRIES; i++)); do
        if curl -fsS --max-time 5 "$url" >/dev/null; then
            echo "==> ${label} is healthy."
            return 0
        fi
        sleep "$HEALTH_INTERVAL"
    done

    echo "install.sh: health check failed for ${label} (${url})." >&2
    return 1
}

activate_nginx_slot() {
    local slot="$1"
    local source="${ROOT}/host/nginx.${slot}.conf"

    echo "==> Activating host nginx for ${slot} (port $(slot_port "$slot"))..."
    run_priv cp "$source" "$NGINX_SITES_AVAILABLE"
    run_priv ln -sf "$NGINX_SITES_AVAILABLE" "$NGINX_SITES_ENABLED"
    run_priv nginx -t
    if command -v systemctl >/dev/null 2>&1; then
        run_priv systemctl reload nginx
    else
        run_priv service nginx reload
    fi
}

stop_legacy_container() {
    if container_running "$LEGACY_CONTAINER"; then
        echo "==> Stopping legacy container ${LEGACY_CONTAINER}..."
        docker stop "$LEGACY_CONTAINER" || true
    fi
    if docker ps -a --format '{{.Names}}' | grep -Fxq "$LEGACY_CONTAINER"; then
        docker rm "$LEGACY_CONTAINER" || true
    fi
}

build_and_start_slot() {
    local slot="$1"
    echo "==> Building Docker image for ${slot}..."
    compose_for_slot "$slot" build --pull
    echo "==> Starting ${slot} container..."
    compose_for_slot "$slot" up -d --remove-orphans
}

bootstrap_blue_green() {
    local slot="blue"
    echo "==> First blue-green install: bootstrapping ${slot}..."
    stop_legacy_container
    build_and_start_slot "$slot"
    install_app_in_container "$(slot_container "$slot")"
    health_check_port "$slot" "$(slot_port "$slot")"
    activate_nginx_slot "$slot"
    write_active_slot "$slot"
    echo "==> Bootstrap complete. Active slot: ${slot}."
}

deploy_blue_green() {
    local active target
    active="$(read_active_slot)"
    target="$(inactive_slot "$active")"

    echo "==> Blue-green deploy to inactive slot: ${target} (active: ${active})..."
    build_and_start_slot "$target"
    install_app_in_container "$(slot_container "$target")"
    health_check_port "$target" "$(slot_port "$target")"
    write_previous_slot "$active"
    activate_nginx_slot "$target"
    write_active_slot "$target"
    echo "==> Deploy complete. Active slot: ${target}."
}

install_legacy() {
    echo "==> Legacy single-container install (${LEGACY_CONTAINER})..."
    if ! container_running "$LEGACY_CONTAINER"; then
        echo "install.sh: container '${LEGACY_CONTAINER}' is not running." >&2
        exit 1
    fi
    install_app_in_container "$LEGACY_CONTAINER"
    echo "==> Install complete."
}

install_blue_green() {
    if [[ -z "$(read_active_slot)" ]]; then
        bootstrap_blue_green
    else
        deploy_blue_green
    fi
}

main() {
    if ! command -v docker >/dev/null 2>&1; then
        echo "install.sh: docker is required but not installed." >&2
        exit 1
    fi

    pull_code

    if uses_blue_green; then
        install_blue_green
    else
        install_legacy
    fi
}

main "$@"
