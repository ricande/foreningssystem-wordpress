#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose run --rm wpcli assoc migrate >/dev/null

version="$(docker compose run --rm wpcli option get assoc_schema_version | tr -d '[:space:]')"

if [ "$version" != "11" ]; then
  echo "Expected schema version 11, got '${version}'." >&2
  exit 1
fi

echo "Lab schema version is 11."

docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-access.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-people.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-board.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-meetings.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-agenda.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-notes.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-actions.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-minutes.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-finalize.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-pdf.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-signed.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-publish.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-board-public.php
