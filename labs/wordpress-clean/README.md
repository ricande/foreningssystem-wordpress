# WordPress clean baseline (in-repo)

Separate Compose stack for a **clean WordPress** with no foreningsplugin and no `assoc_*` options. Named volumes only — nothing from this repository is bind-mounted into the containers at runtime.

Canonical location is now this directory. An older copy may still exist at `/home/ricande/projects/wordpress-clean/`; prefer the in-repo stack going forward.

## How this differs from other labs

| | Bind-mount lab | Clean baseline (this) | Release-test |
| --- | --- | --- | --- |
| Path | repo root `docker-compose.yml` | `labs/wordpress-clean/` | `docker-compose.release-test.yml` |
| Compose project | `foreningsplugin` | `wordpress-clean` | `foreningsplugin-release-test` |
| Plugin | bind-mounted `./plugin` | none | ZIP via `wp plugin install` |
| Purpose | day-to-day source development | empty WP + snap/restore before plugin work | prove packaged ZIP installs |
| Default site | http://localhost:8088 | http://localhost:8088 | http://localhost:8090 |
| Mailpit | http://localhost:8025 | http://localhost:8025 | http://localhost:8026 |

Default ports for this stack are **8088** and **8025**, matching Richard’s existing outside-repo clean stack. Those ports clash with the bind-mount development lab if both try to publish them. Stop one stack, or change `WP_PORT` / `MAILPIT_UI_PORT` in `.env` (for example `8091` / `8027`).

## Quick start (Make from repo root)

```bash
make clean-lab-up          # start db + wordpress + mailpit
make clean-lab-install     # wp core install, sv_SE, Mailpit MU-plugin into volume
make clean-lab-snap        # snapshot wordpress-clean-before-foreningsplugin
make clean-lab-restore     # restore that snapshot
```

Or from this directory:

```bash
cp .env.example .env       # once; edit ports if needed
chmod +x scripts/*.sh
./scripts/install.sh
./scripts/snapshot.sh      # → snapshots/wordpress-clean-before-foreningsplugin/
./scripts/restore.sh       # restore same name (or pass another snapshot name)
```

## Restore command

```bash
# from repo root
make clean-lab-restore

# or
cd labs/wordpress-clean && ./scripts/restore.sh
# or named:
./scripts/restore.sh wordpress-clean-before-foreningsplugin
```

Snapshots under `labs/wordpress-clean/snapshots/` are **gitignored** (database dumps and volume tarballs must not enter git).

## Layout

```text
labs/wordpress-clean/
  docker-compose.yml
  .env.example          # copy to .env (never commit .env)
  README.md
  mu-plugins/mailpit-smtp.php   # source only; install.sh docker-cp into volume
  scripts/install.sh
  scripts/snapshot.sh
  scripts/restore.sh
  snapshots/            # local only
```

## After install

- Locale `sv_SE`, timezone `Europe/Stockholm`
- Admin email `admin@example.test` (synthetic)
- Mailpit MU-plugin copied into the WordPress named volume (not bind-mounted)
- No `foreningsplugin`, no `assoc_*` options (install script verifies)
