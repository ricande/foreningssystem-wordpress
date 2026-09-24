#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

set -a
# shellcheck disable=SC1091
source ./.env
set +a

docker compose up -d --wait

echo "Väntar på wp-config.php..."
ready=0
for _ in $(seq 1 60); do
  if docker compose exec -T wordpress test -f /var/www/html/wp-config.php; then
    ready=1
    break
  fi
  sleep 2
done

if [ "$ready" -ne 1 ]; then
  echo "WordPress skapade ingen wp-config.php." >&2
  exit 1
fi

if docker compose run --rm wpcli core is-installed; then
  echo "WordPress är redan installerat."
else
  docker compose run --rm wpcli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
fi

docker compose run --rm wpcli language core install sv_SE
docker compose run --rm wpcli site switch-language sv_SE
docker compose run --rm wpcli option update timezone_string Europe/Stockholm

docker compose run --rm wpcli plugin activate foreningsplugin

if [ ! -f snapshots/ren-install/db.sql ]; then
  bash scripts/snapshot.sh snap ren-install
else
  echo "Snapshot ren-install finns redan."
fi
