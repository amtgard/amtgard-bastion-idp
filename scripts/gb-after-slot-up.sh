#!/usr/bin/env bash
set -euo pipefail

slot="${1:?slot required}"

WORKER_IMAGE="${WORKER_IMAGE:-amtgard-idp-jwt-worker:latest}"
case "$slot" in
    blue) container="amtgard-idp-blue" ;;
    green) container="amtgard-idp-green" ;;
    *) exit 1 ;;
esac

if docker inspect "$container" >/dev/null 2>&1; then
    image_id="$(docker inspect -f '{{.Image}}' "$container")"
    docker tag "$image_id" "$WORKER_IMAGE"
fi
