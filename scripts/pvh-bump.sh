#!/usr/bin/env bash
# Force the next worker pass to rotate pvh without changing real IAM claims.
# Sets policy_hash to 32 0xFF bytes (not NULs: PDO/AARO drop all-zero BINARY).
# Redis is left stale until validate enqueues and the worker recomputes the real hash.
# Usage: ./scripts/pvh-bump.sh <email>
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EMAIL="${1:-}"
DB_CONTAINER="${DB_CONTAINER:-amtgard-idp-db}"

if [[ -z "$EMAIL" ]]; then
  echo "Usage: $0 <email>" >&2
  exit 1
fi

sql_literal() {
  printf "%s" "$1" | sed "s/'/''/g"
}

SAFE_EMAIL="$(sql_literal "$EMAIL")"

echo "Invalidating policy_hash for ${EMAIL} (claims unchanged; worker will rotate pvh)..."
docker exec -i "$DB_CONTAINER" \
  mariadb -uidp -psecret idp -e \
  "UPDATE user_jwt_generations g
   JOIN users u ON u.id = g.user_id
   SET g.policy_hash = UNHEX(REPEAT('FF', 32))
   WHERE u.email = '${SAFE_EMAIL}';
   SELECT ROW_COUNT() AS generations_bumped;"

"$ROOT/scripts/pvh-inspect.sh" "$EMAIL"
