#!/usr/bin/env bash
# Starts parallel PHP workers in the WordPress container, each with its own database
# connection, released on one shared start time. Each register scenario must save
# exactly once. In board_coverage, worker 1 ends a membership while the others give its
# member a board role, and no assignment may outlast the membership. See ADR-0031.
set -euo pipefail

cd "$(dirname "$0")/.."

workers="${RACE_WORKERS:-8}"
tests=/var/www/html/wp-content/plugins/foreningsplugin/tests
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"; docker compose run --rm wpcli eval-file "$tests/lab-register-race.php" cleanup >/dev/null 2>&1 || true' EXIT

race() {
  local scenario="$1"
  local setup target detail start
  setup="$(docker compose run --rm wpcli eval-file "$tests/lab-register-race.php" setup "$scenario" | tail -n 1)"
  read -r target detail <<<"$setup"
  detail="${detail:-}"
  # Long enough for every worker to load WordPress before the start.
  start="$(( $(date +%s) + 4 ))"
  : >"$scratch/$scenario"

  for worker in $(seq 1 "$workers"); do
    docker compose exec -T wordpress php "$tests/lab-register-race-worker.php" \
      "$scenario" "$start" "$worker" "$target" "$detail" >>"$scratch/$scenario" &
  done

  wait
  docker compose run --rm -v "$scratch:/race:ro" wpcli eval-file "$tests/lab-register-race.php" \
    check "$scenario" "$target" "/race/$scenario" "$workers"
}

race add_period
race register
race update_contact
race board_coverage
