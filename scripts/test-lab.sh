#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose run --rm wpcli assoc migrate >/dev/null

version="$(docker compose run --rm wpcli option get assoc_schema_version | tr -d '[:space:]')"

if [ "$version" != "13" ]; then
  echo "Expected schema version 13, got '${version}'." >&2
  exit 1
fi

echo "Lab schema version is 13."

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
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-board-meeting.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-documents.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-member-count.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-privacy.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-privacy-erase.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-retention.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-member-documents.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-administrator-documents.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-minutes-lock.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-minutes-publish.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-profile.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-members-csv.php
docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-meeting-template.php
