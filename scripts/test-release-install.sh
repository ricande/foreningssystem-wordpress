#!/usr/bin/env bash
# Install dist/foreningsplugin-0.1.0.zip into a fresh WordPress.
# This never uses the development compose project or its volumes.
set -euo pipefail

cd "$(dirname "$0")/.."
root="$(pwd)"
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES COMPOSE_PATH_SEPARATOR || true

project="foreningsplugin-release-test"
file="${root}/docker-compose.release-test.yml"
zip_name="foreningsplugin-0.1.0.zip"

compose() {
  docker compose -p "$project" -f "$file" "$@"
}

on_error() {
  echo "Release install failed. Containers are still available for inspection." >&2
  echo "Cleanup: make release-test-down" >&2
  compose logs --no-color --tail 120 wordpress >&2 || true
  compose logs --no-color --tail 40 db >&2 || true
}
trap on_error ERR

bash scripts/build-plugin-zip.sh

if [ ! -s "dist/${zip_name}" ]; then
  echo "Build did not produce dist/${zip_name}." >&2
  exit 1
fi
chmod a+r "dist/${zip_name}" "dist/${zip_name}.sha256"
chmod a+rx dist

echo "Removing any previous ${project} containers and volumes."
compose down -v --remove-orphans

echo "Starting a fresh MariaDB and WordPress for ${project}."
compose up -d --wait

cid="$(compose ps -q wordpress)"
actual_project="$(docker inspect "$cid" --format '{{index .Config.Labels "com.docker.compose.project"}}')"
if [ "$actual_project" != "$project" ]; then
  echo "Release WordPress is in project '${actual_project}', expected '${project}'." >&2
  exit 1
fi

mounts="$(docker inspect "$cid" --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}')"
printf 'WordPress mounts:\n%s\n' "$mounts"
if printf '%s\n' "$mounts" | grep -F 'wp-content/plugins/foreningsplugin' >/dev/null; then
  echo "Plugin source is bind-mounted into the release WordPress container." >&2
  exit 1
fi
echo "plugin source bind mount = NO"

echo "Waiting for wp-config.php..."
ready=0
for _ in $(seq 1 60); do
  if compose exec -T wordpress test -f /var/www/html/wp-config.php; then
    ready=1
    break
  fi
  sleep 2
done
if [ "$ready" -ne 1 ]; then
  echo "WordPress did not create wp-config.php." >&2
  exit 1
fi

if compose run --rm -T wpcli core is-installed >/dev/null 2>&1; then
  echo "A fresh volume already had WordPress installed." >&2
  exit 1
fi

compose run --rm -T wpcli core install \
  --url="http://localhost:8090" \
  --title="Scratch Test Association" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.test" \
  --skip-email

compose run --rm -T wpcli language core install sv_SE
compose run --rm -T wpcli site switch-language sv_SE
compose run --rm -T wpcli option update timezone_string Europe/Stockholm

compose exec -T wordpress bash -lc 'rm -f /var/www/html/wp-content/debug.log; touch /var/www/html/wp-content/debug.log; chown www-data:www-data /var/www/html/wp-content/debug.log'

if compose run --rm -T wpcli plugin is-installed foreningsplugin >/dev/null 2>&1; then
  echo "foreningsplugin is already installed before the ZIP install." >&2
  exit 1
fi
if compose exec -T wordpress test -e /var/www/html/wp-content/plugins/foreningsplugin; then
  echo "wp-content/plugins/foreningsplugin already exists." >&2
  exit 1
fi
echo "plugin absent before installation"

if compose run --rm -T wpcli option get assoc_schema_version >/dev/null 2>&1; then
  echo "assoc_schema_version exists before plugin activation." >&2
  exit 1
fi
echo "assoc_schema_version before = absent"

echo "Installing ${zip_name} through WP-CLI."
compose run --rm -T wpcli plugin install "/packages/${zip_name}" --activate

compose run --rm -T wpcli plugin is-installed foreningsplugin
compose run --rm -T wpcli plugin is-active foreningsplugin
echo "activation successful"
echo "plugin active"

schema="$(compose run --rm -T wpcli option get assoc_schema_version | tr -d '[:space:]')"
if [ "$schema" != "16" ]; then
  echo "assoc_schema_version after = '${schema}', expected 16." >&2
  exit 1
fi
echo "assoc_schema_version after = 16"

setup="$(compose run --rm -T wpcli option get assoc_setup_version | tr -d '[:space:]')"
if [ "$setup" != "0" ]; then
  echo "assoc_setup_version after = '${setup}', expected 0 for fresh install." >&2
  exit 1
fi
echo "assoc_setup_version after = 0 (incomplete)"

pending="$(compose run --rm -T wpcli option get assoc_setup_redirect_pending | tr -d '[:space:]')"
if [ "$pending" != "1" ]; then
  echo "assoc_setup_redirect_pending after = '${pending}', expected 1." >&2
  exit 1
fi
echo "assoc_setup_redirect_pending after = 1"
echo "WP-CLI activation did not consume the admin redirect marker"

version="$(compose run --rm -T wpcli plugin get foreningsplugin --field=version | tr -d '[:space:]')"
if [ "$version" != "0.1.0" ]; then
  echo "Installed plugin version is '${version}', expected 0.1.0." >&2
  exit 1
fi
echo "installed plugin version = 0.1.0"

plugin_root="/var/www/html/wp-content/plugins/foreningsplugin"
for present in foreningsplugin.php autoload.php src assets languages; do
  if ! compose exec -T wordpress test -e "${plugin_root}/${present}"; then
    echo "Installed plugin is missing ${present}." >&2
    exit 1
  fi
done
for absent in tests composer.json docker-compose.yml .env .git scripts; do
  if compose exec -T wordpress test -e "${plugin_root}/${absent}"; then
    echo "Installed plugin contains ${absent}." >&2
    exit 1
  fi
done
echo "installed tree matches the package"

echo "Running scratch smoke."
compose run --rm -T -e RELEASE_PHASE=install wpcli eval-file /release-smoke.php

echo "Deactivating and reactivating at schema 16."
compose run --rm -T wpcli plugin deactivate foreningsplugin
compose run --rm -T wpcli plugin activate foreningsplugin
schema="$(compose run --rm -T wpcli option get assoc_schema_version | tr -d '[:space:]')"
if [ "$schema" != "16" ]; then
  echo "assoc_schema_version after reactivation = '${schema}', expected 16." >&2
  exit 1
fi
compose run --rm -T wpcli plugin is-active foreningsplugin
compose run --rm -T -e RELEASE_PHASE=reactivate wpcli eval-file /release-smoke.php
echo "reactivation kept schema 16 and the scratch data"

debug="$(compose exec -T wordpress cat /var/www/html/wp-content/debug.log 2>/dev/null || true)"
plugin_bad="$(printf '%s\n' "$debug" | grep -E 'foreningsplugin' | grep -E 'PHP Fatal|PHP Warning|PHP Deprecated|PHP Notice|Uncaught|WordPress database error' || true)"
db_bad="$(printf '%s\n' "$debug" | grep -F 'WordPress database error' || true)"
other="$(printf '%s\n' "$debug" | grep -E 'PHP Fatal|PHP Warning|PHP Deprecated|Uncaught|WordPress database error' | grep -v 'foreningsplugin' || true)"
container_bad="$(compose logs --no-color wordpress 2>&1 | grep -E 'plugins/foreningsplugin' | grep -E 'PHP Fatal|PHP Warning|PHP Deprecated|Uncaught' || true)"

if [ -n "$plugin_bad" ] || [ -n "$db_bad" ] || [ -n "$container_bad" ]; then
  echo "Plugin-originating log failures:" >&2
  printf '%s\n' "$plugin_bad" "$db_bad" "$container_bad" >&2
  exit 1
fi

if [ -n "$other" ]; then
  echo "Unrelated platform noise:"
  printf '%s\n' "$other"
else
  echo "Unrelated platform noise: none in the plugin-window debug log"
fi

wp_version="$(compose run --rm -T wpcli core version | tr -d '[:space:]')"
php_version="$(compose exec -T wordpress php -r 'echo PHP_VERSION;')"
db_version="$(compose exec -T db mariadb --version | tr -d '\r')"

echo "RELEASE TEST PASS"
echo "Docker project ${project}"
echo "WordPress ${wp_version}"
echo "PHP ${php_version}"
echo "MariaDB ${db_version}"
echo "Site http://localhost:8090"
echo "Mailpit http://localhost:8026"
echo "plugin source bind mount = NO"
echo "Cleanup: make release-test-down"
trap - ERR
