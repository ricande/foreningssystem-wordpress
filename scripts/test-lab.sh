#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose run --rm wpcli assoc migrate >/dev/null

version="$(docker compose run --rm wpcli option get assoc_schema_version | tr -d '[:space:]')"

if [ "$version" != "1" ]; then
  echo "Expected schema version 1, got '${version}'." >&2
  exit 1
fi

echo "Lab schema version is 1."

docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/foreningsplugin/tests/lab-access.php
