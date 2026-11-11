#!/bin/sh
set -e

APP_ROOT="/var/www/idp.amtgard.com"

mkdir -p "${APP_ROOT}/logs" "${APP_ROOT}/cache/twig" "${APP_ROOT}/cache/di"
chown -R www-data:www-data "${APP_ROOT}/logs" "${APP_ROOT}/cache" 2>/dev/null || true

exec "$@"
