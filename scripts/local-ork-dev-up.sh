#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NETWORK="${NETWORK:-amtgard-idp-shared}"

echo "==> Ensuring Docker network ${NETWORK}..."
docker network create "$NETWORK" 2>/dev/null || true

echo "==> Starting IDP (dev + local ORK overlay)..."
docker compose --project-directory "$ROOT" -p amtgard-idp \
  -f docker/compose.prod.yml \
  -f docker/compose.blue.yml \
  -f docker/compose.dev.yml \
  -f docker/compose.local-ork.yml \
  up -d --build amtgardidpapp amtgardidpdb

echo "==> Migrating IDP database..."
docker exec amtgard-idp bash -lc 'cd /var/www/idp.amtgard.com && vendor/bin/phinx migrate'

cat <<EOF

IDP is up on http://localhost:37080 (container: amtgard-idp).

Next — from your ORK3 checkout:
  cp .dev.env.example .dev.env   # SES only; IDP callback uses ORK config.dev.php IDP_API_URL / IDP_CLIENT_*
  docker compose -f docker-compose.php8-app.dev.yml -f docker-compose.local-idp.yml up -d --build

Full checklist: agent/cursor/ork-link-possession/local-docker-validation.md

EOF
