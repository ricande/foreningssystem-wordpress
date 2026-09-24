#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

set -a
# shellcheck disable=SC1091
source ./.env
set +a

action="${1:-}"
name="${2:-}"

if [ "$action" != "snap" ] && [ "$action" != "restore" ]; then
  echo "Användning: scripts/snapshot.sh snap|restore <namn>" >&2
  exit 1
fi

if [[ ! "$name" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "Ange ett namn, t.ex. make snap name=ren-install" >&2
  exit 1
fi

dest="snapshots/$name"

snap() {
  mkdir -p "$dest"
  docker compose exec -T wordpress bash -c 'mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads'
  docker compose exec -T db mariadb-dump \
    -u"$MYSQL_USER" \
    -p"$MYSQL_PASSWORD" \
    --single-transaction \
    --add-drop-table \
    --routines \
    --default-character-set=utf8mb4 \
    "$MYSQL_DATABASE" > "$dest/db.sql"
  docker compose exec -T wordpress tar czf - -C /var/www/html/wp-content uploads > "$dest/uploads.tar.gz"
  {
    echo "name=$name"
    echo "created=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    docker compose run --rm wpcli core version
  } > "$dest/meta.txt"
  echo "Snapshot $name sparad i $dest"
}

restore() {
  if [ ! -f "$dest/db.sql" ] || [ ! -f "$dest/uploads.tar.gz" ]; then
    echo "Snapshot $name saknas i $dest" >&2
    exit 1
  fi

  docker compose exec -T db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e \
    "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%'; FLUSH PRIVILEGES;"
  docker compose exec -T db mariadb \
    -u"$MYSQL_USER" \
    -p"$MYSQL_PASSWORD" \
    --default-character-set=utf8mb4 \
    "$MYSQL_DATABASE" < "$dest/db.sql"
  docker compose exec -T wordpress rm -rf /var/www/html/wp-content/uploads
  docker compose exec -T wordpress tar xzf - -C /var/www/html/wp-content < "$dest/uploads.tar.gz"
  docker compose exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads
  echo "Snapshot $name återställd"
}

"$action"
