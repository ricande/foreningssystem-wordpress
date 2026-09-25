#!/usr/bin/env bash
# Build dist/foreningsplugin-<version>.zip from the runtime plugin tree.
# The archive root is foreningsplugin/, which is the WordPress plugin slug.
set -euo pipefail

cd "$(dirname "$0")/.."

expected="0.1.0"
header="$(sed -n 's/^ \* Version:[[:space:]]*//p' plugin/foreningsplugin.php | head -n 1 | tr -d '\r')"
constant="$(sed -n "s/.*const VERSION = '\\([^']*\\)'.*/\\1/p" plugin/src/Infrastructure/WordPress/Plugin.php | head -n 1 | tr -d '\r')"

if [ "$header" != "$expected" ] || [ "$constant" != "$expected" ]; then
  echo "Version mismatch. Header='${header}' Plugin::VERSION='${constant}' expected='${expected}'." >&2
  echo "Packaging does not change either value." >&2
  exit 1
fi

for required in plugin/foreningsplugin.php plugin/autoload.php plugin/src plugin/assets plugin/languages plugin/assets/profile.js plugin/languages/foreningsplugin-sv_SE.mo plugin/languages/foreningsplugin-sv_SE.po; do
  if [ ! -e "$required" ]; then
    echo "Missing runtime file: $required" >&2
    exit 1
  fi
done

mkdir -p dist
stage="$(mktemp -d "${PWD}/dist/.build-stage.XXXXXX")"
extract="$(mktemp -d "${PWD}/dist/.build-extract.XXXXXX")"
cleanup() {
  rm -rf "$stage" "$extract"
}
trap cleanup EXIT

mkdir -p "$stage/foreningsplugin"
cp plugin/foreningsplugin.php plugin/autoload.php "$stage/foreningsplugin/"
cp -R plugin/src plugin/assets plugin/languages "$stage/foreningsplugin/"
find "$stage" -name '.DS_Store' -delete
find "$stage" -exec touch -t 202001010000 {} +

zip_path="$PWD/dist/foreningsplugin-${expected}.zip"
rm -f "$zip_path" "${zip_path}.sha256"
(
  cd "$stage"
  find foreningsplugin -type f | LC_ALL=C sort | zip -X -q -@ "$zip_path"
)

if [ ! -s "$zip_path" ]; then
  echo "ZIP is missing or empty: $zip_path" >&2
  exit 1
fi

entries=()
while IFS= read -r entry; do
  if [ -n "$entry" ]; then
    entries+=("$entry")
  fi
done < <(unzip -Z1 "$zip_path")
if [ "${#entries[@]}" -eq 0 ]; then
  echo "ZIP has no entries." >&2
  exit 1
fi

tops="$(printf '%s\n' "${entries[@]}" | awk -F/ 'NF { print $1 }' | sort -u)"
if [ "$tops" != "foreningsplugin" ]; then
  echo "ZIP must contain exactly one top-level directory, foreningsplugin/." >&2
  printf '%s\n' "$tops" >&2
  exit 1
fi

require_entry() {
  local needle="$1"
  local entry
  for entry in "${entries[@]}"; do
    if [ "$entry" = "$needle" ]; then
      return 0
    fi
  done
  echo "ZIP is missing $needle" >&2
  exit 1
}

require_prefix() {
  local prefix="$1"
  local entry
  for entry in "${entries[@]}"; do
    case "$entry" in
      "$prefix"*) return 0 ;;
    esac
  done
  echo "ZIP is missing $prefix" >&2
  exit 1
}

require_entry "foreningsplugin/foreningsplugin.php"
require_entry "foreningsplugin/autoload.php"
require_entry "foreningsplugin/assets/profile.js"
require_entry "foreningsplugin/languages/foreningsplugin-sv_SE.mo"
require_entry "foreningsplugin/languages/foreningsplugin-sv_SE.po"
require_prefix "foreningsplugin/src/"
require_prefix "foreningsplugin/assets/"
require_prefix "foreningsplugin/languages/"

forbidden_entry() {
  echo "ZIP contains excluded development material: $1" >&2
  exit 1
}

for entry in "${entries[@]}"; do
  case "$entry" in
    foreningsplugin/tests|foreningsplugin/tests/*|\
    foreningsplugin/.git|foreningsplugin/.git/*|\
    foreningsplugin/.github|foreningsplugin/.github/*|\
    foreningsplugin/.cursor|foreningsplugin/.cursor/*|\
    foreningsplugin/docs|foreningsplugin/docs/*|\
    foreningsplugin/adr|foreningsplugin/adr/*|\
    foreningsplugin/scripts|foreningsplugin/scripts/*|\
    foreningsplugin/snapshots|foreningsplugin/snapshots/*|\
    foreningsplugin/dist|foreningsplugin/dist/*|\
    foreningsplugin/vendor|foreningsplugin/vendor/*|\
    foreningsplugin/node_modules|foreningsplugin/node_modules/*|\
    */.env|*/.env.example|foreningsplugin/.env|foreningsplugin/.env.example)
      forbidden_entry "$entry"
      ;;
  esac

  base="${entry##*/}"
  case "$base" in
    composer.json|composer.lock|phpunit.xml|phpunit.xml.dist|Makefile|\
    docker-compose.yml|docker-compose.release-test.yml|.env|.env.example|\
    AGENTS.md|CURSOR_START_PROMPT.md|PROJECT_STATE.md|OPEN_QUESTIONS.md|\
    package.json|package-lock.json|PACKAGE.json)
      forbidden_entry "$entry"
      ;;
  esac
done

packaged_header="$(unzip -p "$zip_path" foreningsplugin/foreningsplugin.php | sed -n 's/^ \* Version:[[:space:]]*//p' | head -n 1 | tr -d '\r')"
packaged_constant="$(unzip -p "$zip_path" foreningsplugin/src/Infrastructure/WordPress/Plugin.php | sed -n "s/.*const VERSION = '\\([^']*\\)'.*/\\1/p" | head -n 1 | tr -d '\r')"
if [ "$packaged_header" != "$expected" ] || [ "$packaged_constant" != "$expected" ]; then
  echo "Packaged version mismatch. Header='${packaged_header}' Plugin::VERSION='${packaged_constant}'." >&2
  exit 1
fi

unzip -q "$zip_path" -d "$extract"
lint_log="$extract/php-lint.txt"
if ! docker run --rm -v "$extract:/pkg" -w /pkg php:8.3-cli \
  bash -lc 'find foreningsplugin -name "*.php" -print0 | xargs -0 -n1 php -l' >"$lint_log"; then
  echo "Packaged PHP syntax failed." >&2
  cat "$lint_log" >&2
  exit 1
fi
if grep -v 'No syntax errors detected' "$lint_log" | grep -q .; then
  echo "Packaged PHP syntax failed." >&2
  cat "$lint_log" >&2
  exit 1
fi

php_count="$(find "$extract/foreningsplugin" -name '*.php' | wc -l | tr -d ' ')"
if command -v sha256sum >/dev/null 2>&1; then
  hash="$(sha256sum "$zip_path" | awk '{print $1}')"
else
  hash="$(shasum -a 256 "$zip_path" | awk '{print $1}')"
fi
printf '%s  %s\n' "$hash" "foreningsplugin-${expected}.zip" >"${zip_path}.sha256"
chmod a+r "$zip_path" "${zip_path}.sha256"
chmod a+rx dist

bytes="$(wc -c <"$zip_path" | tr -d ' ')"
echo "ZIP dist/foreningsplugin-${expected}.zip"
echo "Bytes ${bytes}"
echo "Entries ${#entries[@]}"
echo "SHA-256 ${hash}"
echo "Top-level foreningsplugin/"
echo "Version ${expected}"
echo "Packaged PHP syntax OK (${php_count} files)"
