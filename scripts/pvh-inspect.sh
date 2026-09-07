#!/usr/bin/env bash
# Dump MySQL user_jwt_generations + Redis pvh:{uuid}:{aud} for a local walkthrough.
# Usage: ./scripts/pvh-inspect.sh [email]
set -euo pipefail

EMAIL="${1:-}"
DB_CONTAINER="${DB_CONTAINER:-amtgard-idp-db}"
SESSIONS_CONTAINER="${SESSIONS_CONTAINER:-amtgard-idp-sessions}"

mysql_exec() {
  docker exec -i "$DB_CONTAINER" mariadb -uidp -psecret idp "$@"
}

sql_literal() {
  printf "%s" "$1" | sed "s/'/''/g"
}

echo "== user_jwt_generations =="
if [[ -z "$EMAIL" ]]; then
  mysql_exec -e "SELECT u.email, g.user_uuid, g.aud, g.pvh, g.prev_pvh, HEX(g.policy_hash) AS policy_hash_hex, g.updated_at
FROM user_jwt_generations g
JOIN users u ON u.id = g.user_id
ORDER BY g.updated_at DESC
LIMIT 20;"
  echo
  echo "Pass an email to also dump matching Redis keys: $0 you@example.com"
  exit 0
fi

SAFE_EMAIL="$(sql_literal "$EMAIL")"
mysql_exec -e "SELECT u.id, u.email, u.user_id AS user_uuid, g.aud, g.pvh, g.prev_pvh, HEX(g.policy_hash) AS policy_hash_hex, g.updated_at
FROM users u
LEFT JOIN user_jwt_generations g ON g.user_id = u.id
WHERE u.email = '${SAFE_EMAIL}';"

echo
echo "== Redis pvh keys =="
FOUND=0
while IFS=$'\t' read -r UUID AUD; do
  [[ -z "${UUID:-}" ]] && continue
  FOUND=1
  KEY="pvh:${UUID}:${AUD}"
  echo "-- GET ${KEY}"
  docker exec "$SESSIONS_CONTAINER" redis-cli GET "$KEY" || true
  echo
done < <(mysql_exec -N -e "SELECT g.user_uuid, g.aud
FROM users u
JOIN user_jwt_generations g ON g.user_id = u.id
WHERE u.email = '${SAFE_EMAIL}';")

if [[ "$FOUND" -eq 0 ]]; then
  echo "(no generation rows for ${EMAIL})"
fi
