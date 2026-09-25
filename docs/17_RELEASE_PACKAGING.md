# Release packaging

This describes how to build a WordPress plugin ZIP and install that ZIP in a clean WordPress. A passing run means the package and a fresh install were validated. It is not a public release and it is not a production-ready claim.

No Git tag or GitHub Release is created by these commands.

## Development lab, clean baseline, and release-test lab

The development lab in `docker-compose.yml` bind-mounts `./plugin` into WordPress. It proves the source tree. It does not prove that a distributable archive installs.

The **clean WordPress baseline** lives in `labs/wordpress-clean/` (Compose project `wordpress-clean`). It uses named volumes only — no bind mount of `./plugin`, project root, `src`, `dist`, or any foreningssystem plugin path. After `make clean-lab-install` the site has no foreningsplugin and no `assoc_*` options. Mailpit is provided by an MU-plugin **copied into the volume** (source file shipped under `labs/wordpress-clean/mu-plugins/`, installed by script). Snapshot name: `wordpress-clean-before-foreningsplugin`. Restore: `make clean-lab-restore` or `labs/wordpress-clean/scripts/restore.sh`. Default ports are 8088 / 8025 and therefore conflict with the bind-mount lab if both are up; change ports in `labs/wordpress-clean/.env` or stop one stack. See `labs/wordpress-clean/README.md`.

The release-test lab is a separate Compose project, `foreningsplugin-release-test`, defined in `docker-compose.release-test.yml`. It uses its own database, WordPress files, and Mailpit data. It does not mount `./plugin`. The plugin arrives only through:

```bash
wp plugin install /packages/foreningsplugin-0.1.0.zip --activate
```

WP-CLI may read `./dist` at `/packages` so it can see the ZIP. The web container does not.

| | Development lab | Clean baseline | Release-test lab |
| --- | --- | --- | --- |
| Compose project | `foreningsplugin` | `wordpress-clean` | `foreningsplugin-release-test` |
| Site | http://localhost:8088 | http://localhost:8088 (default; may clash) | http://localhost:8090 |
| Mailpit | http://localhost:8025 | http://localhost:8025 (default; may clash) | http://localhost:8026 |
| Plugin | bind-mounted source | none | installed ZIP |
| Volumes | `db_data`, `wp_data`, `mailpit_data` | `wp_data`, `db_data` (project-prefixed) | `release_db`, `release_wp`, `release_mailpit` |

`make release-test` destroys any previous release-test containers and volumes, then starts new ones. It does not run `make restore` / `make clean-lab-restore` and it does not touch the development lab or the clean baseline.

The scratch administrator is the disposable `admin` / `admin` account from `.env.example`. Those values are hardcoded in the release Compose file so the test does not read the development `.env`.

## Build the ZIP

```bash
make package
```

That runs `scripts/build-plugin-zip.sh`. The current plugin version is `0.1.0`. The script fails if the plugin header and `Plugin::VERSION` disagree, or if either is not `0.1.0`. It does not rewrite either value.

Output:

```text
dist/foreningsplugin-0.1.0.zip
dist/foreningsplugin-0.1.0.zip.sha256
```

The script prints the byte size, entry count, and SHA-256. Generated ZIP files and checksums are gitignored. File modification times inside the archive are normalized so two builds from the same tree have the same file list and file contents. The ZIP container bytes may still differ.

After the archive is written, the script extracts it and runs `php -l` on the packaged PHP files with `php:8.3-cli`.

## Package contents

The archive contains one top-level directory, `foreningsplugin/`, which is the WordPress plugin slug.

Included, because the plugin loads them at runtime:

- `foreningsplugin.php` and `autoload.php`
- `src/`, the application code
- `assets/profile.js`, enqueued by the association profile screen
- `languages/`, including `foreningsplugin-sv_SE.mo`, which WordPress loads for Swedish, and the matching `.po`

No other runtime file sits at the plugin root. `tests/` is development-only and is excluded.

Also excluded: Git metadata, `.github/`, `.cursor/`, docs, ADRs, scripts, snapshots, `dist/`, Composer and npm files, PHPUnit and Make files, Docker and `.env` files, and repository notes such as `AGENTS.md`.

The build fails if that layout contract is broken.

## Fresh-install validation

```bash
make release-test
```

The script builds the ZIP, removes any previous `foreningsplugin-release-test` volumes, and starts:

- `wordpress:php8.3-apache`
- `mariadb:11`
- `wordpress:cli-php8.3`
- an isolated Mailpit, used only so a provisioned member account can send its notification

WordPress is installed empty. The site language is `sv_SE` and the timezone is `Europe/Stockholm`. The test checks that `foreningsplugin` is absent, installs the ZIP, and activates it. Schema must move from no `assoc_schema_version` option to `16` during that activation. The smoke test then checks tables derived from the installed migrations, built-in board roles and meeting types, administrator capabilities, empty admin screens, and a small synthetic association flow including minutes PDF output.

On success the stack stays up at http://localhost:8090 so it can be inspected. Mailpit for this stack is http://localhost:8026.

```bash
make release-test-down
```

That removes the release-test containers and volumes. A failed run leaves them in place and prints recent container logs.

Private document files in this Apache lab are stored under `wp-content/uploads/assoc-private`, with an `.htaccess` guard. That does not prove the directory is protected on Nginx.

## CI

The `package-install` job in `.github/workflows/tests.yml` runs the same fresh ZIP install. The existing `phpunit` and `lab` jobs are unchanged. The lab job still bind-mounts the source tree.
