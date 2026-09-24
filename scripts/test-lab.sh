#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose run --rm wpcli assoc migrate >/dev/null

version="$(docker compose run --rm wpcli option get assoc_schema_version | tr -d '[:space:]')"

if [ "$version" != "5" ]; then
  echo "Expected schema version 5, got '${version}'." >&2
  exit 1
fi

echo "Lab schema version is 5."

docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-access.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-people.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-board.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-meetings.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-agenda.php
