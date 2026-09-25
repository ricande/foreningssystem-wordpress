#!/usr/bin/env bash
# Restore snapshot wordpress-clean-before-foreningsplugin (or named arg).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
NAME="${1:-wordpress-clean-before-foreningsplugin}"
SNAP_DIR="${ROOT}/snapshots/${NAME}"

if [[ ! -d "${SNAP_DIR}" ]]; then
  echo "Snapshot not found: ${SNAP_DIR}" >&2
  echo "Create one with: ./scripts/snapshot.sh ${NAME}" >&2
  exit 1
fi
if [[ ! -f "${SNAP_DIR}/database.sql" || ! -f "${SNAP_DIR}/wp_data.tar.gz" ]]; then
  echo "Incomplete snapshot: need database.sql and wp_data.tar.gz" >&2
  exit 1
fi

# Prefer snapshot's local env/compose if present (do not commit these)
# shellcheck disable=SC1091
if [[ -f "${SNAP_DIR}/.env" ]]; then
  cp "${SNAP_DIR}/.env" "${ROOT}/.env"
fi
if [[ -f "${SNAP_DIR}/docker-compose.yml" ]]; then
  cp "${SNAP_DIR}/docker-compose.yml" "${ROOT}/docker-compose.yml"
fi
if [[ ! -f .env ]]; then
  cp .env.example .env
fi
set -a; source .env; set +a

PROJECT="${COMPOSE_PROJECT_NAME:-wordpress-clean}"
WP_VOLUME="${PROJECT}_wp_data"

compose() { docker compose --env-file .env "$@"; }

echo "==> Restoring ${NAME}"

compose up -d db mailpit
# Wait for DB
for i in $(seq 1 60); do
  if compose exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# Ensure WP volume exists, stop wordpress while we rewrite filesystem
compose stop wordpress 2>/dev/null || true
compose up -d --no-start wordpress 2>/dev/null || true

docker run --rm \
  -v "${WP_VOLUME}:/to" \
  -v "${SNAP_DIR}:/from:ro" \
  alpine:3.20 \
  sh -c 'rm -rf /to/* /to/.[!.]* /to/..?* 2>/dev/null; tar -C /to -xzf /from/wp_data.tar.gz'

# Import DB (drop & recreate for exact state)
compose exec -T db mariadb -u root -p"${MYSQL_ROOT_PASSWORD}" -e \
  "DROP DATABASE IF EXISTS \`${MYSQL_DATABASE}\`; CREATE DATABASE \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;"
compose exec -T db mariadb -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" < "${SNAP_DIR}/database.sql"

compose up -d wordpress mailpit

echo "==> Restored. Site: ${WP_URL:-http://localhost:8088}  Mailpit: http://localhost:${MAILPIT_UI_PORT:-8025}"
