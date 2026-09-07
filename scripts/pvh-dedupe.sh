#!/usr/bin/env bash
# Prove SetQueue coalesces many /resources/validate 200s into one PVH job.
# Pauses the worker so it cannot drain between hits; sequential publishes
# for the same {userUuid}:{aud} update the hash set without a second LPUSH.
#
# Usage:
#   JWT=<token> ./scripts/pvh-dedupe.sh [count]
#   COOKIE='PHPSESSID=...' ./scripts/pvh-dedupe.sh [count]
# Token: browser console localStorage.getItem('amtgard_idp_jwt')
set -euo pipefail

COUNT="${1:-8}"
BASE_URL="${BASE_URL:-http://localhost:37080}"
WORKER_CONTAINER="${WORKER_CONTAINER:-amtgard-idp-jwt-worker}"
SESSIONS_CONTAINER="${SESSIONS_CONTAINER:-amtgard-idp-sessions}"
QUEUE="${REDIS_PVH_QUEUE_NAME:-amtgard-idp-pvh}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-idp}"

if ! [[ "$COUNT" =~ ^[1-9][0-9]*$ ]]; then
  echo "Usage: JWT=<token> $0 [count]" >&2
  exit 1
fi

fetch_jwt_from_session() {
  local body
  body="$(curl -sS -H "Cookie: ${COOKIE}" "${BASE_URL}/resources/jwt")"
  python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("compact_jwt") or d.get("jwt") or "")' <<<"$body"
}

JWT="${JWT:-}"
if [[ -z "$JWT" && -n "${COOKIE:-}" ]]; then
  JWT="$(fetch_jwt_from_session)"
fi
if [[ -z "$JWT" ]]; then
  echo "Set JWT (localStorage amtgard_idp_jwt) or COOKIE=PHPSESSID=..." >&2
  exit 1
fi

redis() {
  docker exec "$SESSIONS_CONTAINER" redis-cli "$@"
}

enqueue_log_count() {
  docker exec "$APP_CONTAINER" sh -c \
    "grep -c 'jwt pvh enqueue' /var/www/idp.amtgard.com/logs/app.log 2>/dev/null || echo 0"
}

WORKER_PAUSED=0
cleanup() {
  if [[ "$WORKER_PAUSED" -eq 1 ]]; then
    docker unpause "$WORKER_CONTAINER" >/dev/null 2>&1 || true
    WORKER_PAUSED=0
  fi
}
trap cleanup EXIT

echo "== Pausing ${WORKER_CONTAINER} (so the set cannot drain mid-burst) =="
docker pause "$WORKER_CONTAINER"
WORKER_PAUSED=1

ENQUEUE_BEFORE="$(enqueue_log_count)"
ENQUEUE_BEFORE="${ENQUEUE_BEFORE//$'\r'/}"

echo "== GET /resources/validate x${COUNT} =="
STATUSES=()
for _ in $(seq 1 "$COUNT"); do
  STATUSES+=("$(curl -sS -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer ${JWT}" \
    -H "Accept: application/json" \
    "${BASE_URL}/resources/validate")")
done
echo "HTTP ${STATUSES[*]}"

echo
echo "== Redis ${QUEUE} after burst =="
echo -n "queue LLEN  "; redis LLEN "${QUEUE}:queue"
echo -n "set   HLEN  "; redis HLEN "${QUEUE}:set"
echo "set   HKEYS"
redis HKEYS "${QUEUE}:set"
echo -n "redrive LLEN "; redis LLEN "${QUEUE}:redrive"

ENQUEUE_AFTER="$(enqueue_log_count)"
ENQUEUE_AFTER="${ENQUEUE_AFTER//$'\r'/}"
ENQUEUE_DELTA=$((ENQUEUE_AFTER - ENQUEUE_BEFORE))
echo
echo "app.log jwt pvh enqueue lines this burst: ${ENQUEUE_DELTA} (one per 200; SetQueue still coalesces)"

echo
echo "== Unpausing worker =="
docker unpause "$WORKER_CONTAINER"
WORKER_PAUSED=0

# Worker backoff is 0–100ms; give it a moment to pull the single job.
sleep 1
echo "== Worker since unpause =="
docker logs --since 5s "$WORKER_CONTAINER" 2>&1 \
  | grep -E 'jwt pvh worker dequeued|jwt pvh refresh' \
  || echo "(no dequeue/refresh in the last 5s)"
