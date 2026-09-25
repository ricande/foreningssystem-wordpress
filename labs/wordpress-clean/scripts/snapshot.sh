#!/usr/bin/env bash
# Create snapshot: wordpress-clean-before-foreningsplugin
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
NAME="${1:-wordpress-clean-before-foreningsplugin}"
SNAP_DIR="${ROOT}/snapshots/${NAME}"

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and run install.sh first" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

PROJECT="${COMPOSE_PROJECT_NAME:-wordpress-clean}"
WP_VOLUME="${PROJECT}_wp_data"

compose() { docker compose --env-file .env "$@"; }

mkdir -p "${SNAP_DIR}"
echo "==> Snapshotting to ${SNAP_DIR}"

# Consistent DB dump
compose exec -T db mariadb-dump \
  -u root -p"${MYSQL_ROOT_PASSWORD}" \
  --single-transaction --routines --triggers \
  "${MYSQL_DATABASE}" > "${SNAP_DIR}/database.sql"

# Prefer docker run tar over needing host root on mountpoint
compose stop wordpress wpcli 2>/dev/null || true
docker run --rm \
  -v "${WP_VOLUME}:/from:ro" \
  -v "${SNAP_DIR}:/to" \
  alpine:3.20 \
  tar -C /from -czf /to/wp_data.tar.gz .
compose start wordpress

# Compose + env config copy (local only; snapshots/ is gitignored)
cp docker-compose.yml "${SNAP_DIR}/docker-compose.yml"
cp .env "${SNAP_DIR}/.env"
cp -a mu-plugins "${SNAP_DIR}/mu-plugins"
printf '%s\n' "${NAME}" > "${SNAP_DIR}/SNAPSHOT_NAME"
date -u +%Y-%m-%dT%H:%M:%SZ > "${SNAP_DIR}/CREATED_AT"

echo "==> Snapshot ready: ${SNAP_DIR}"
ls -lah "${SNAP_DIR}"
