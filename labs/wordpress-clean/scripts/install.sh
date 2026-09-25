#!/usr/bin/env bash
# Install / bootstrap clean WordPress (sv_SE) + Mailpit MU-plugin.
# After install: no foreningsplugin, no assoc_* options.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "==> Created .env from .env.example (edit ports/passwords if needed)"
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

compose() { docker compose --env-file .env "$@"; }
wp() { compose --profile tools run --rm wpcli "$@"; }

echo "==> Starting stack (project: ${COMPOSE_PROJECT_NAME:-wordpress-clean})"
compose up -d db mailpit wordpress

echo "==> Waiting for WordPress HTTP"
for i in $(seq 1 60); do
  if compose exec -T wordpress curl -fsS http://127.0.0.1 >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "==> Waiting for DB connectivity via WP-CLI"
for i in $(seq 1 60); do
  if wp core is-installed >/dev/null 2>&1 || wp db check >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  echo "==> Installing WordPress (${WP_LOCALE})"
  wp core install \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
fi

echo "==> Locale / timezone"
wp language core install "${WP_LOCALE}"
wp site switch-language "${WP_LOCALE}"
wp option update timezone_string "${WP_TIMEZONE}"

echo "==> Install Mailpit MU-plugin into named volume (docker cp, not bind mount)"
compose exec -T -u root wordpress mkdir -p /var/www/html/wp-content/mu-plugins
CID="$(compose ps -q wordpress)"
docker cp "${ROOT}/mu-plugins/mailpit-smtp.php" "${CID}:/var/www/html/wp-content/mu-plugins/mailpit-smtp.php"
compose exec -T -u root wordpress chown www-data:www-data /var/www/html/wp-content/mu-plugins/mailpit-smtp.php

echo "==> Verify clean baseline (no foreningsplugin, no assoc_*)"
if wp plugin is-installed foreningsplugin 2>/dev/null; then
  echo "ERROR: foreningsplugin is present; clean baseline must not include it" >&2
  exit 1
fi
ASSOC_OPTS="$(wp option list --search='assoc_*' --field=option_name 2>/dev/null || true)"
if [[ -n "${ASSOC_OPTS}" ]]; then
  echo "ERROR: assoc_* options present on clean baseline:" >&2
  echo "${ASSOC_OPTS}" >&2
  exit 1
fi

echo "==> Done. Site: ${WP_URL}  Mailpit UI: http://localhost:${MAILPIT_UI_PORT}"
echo "    Snapshot next: ./scripts/snapshot.sh   (or: make clean-lab-snap)"
