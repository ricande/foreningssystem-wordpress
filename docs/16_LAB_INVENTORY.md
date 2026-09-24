# Lab inventory

Inspected 2026-09-24. This is the development lab on the project owner's Mac. It is Docker Desktop, not a separate virtual machine. No passwords are recorded here.

| Item | Value |
|---|---|
| Host | macOS 26.5.2, Apple Silicon |
| Container runtime | Docker Desktop 29.4.3 |
| Web | `wordpress:php8.3-apache`, Apache 2.4.68, PHP 8.3.33 |
| Database | `mariadb:11` |
| WordPress | 7.1.2 |
| URL | `http://localhost:8088` |
| HTTPS | No |
| Document root | `/var/www/html` inside the `wordpress` service |
| WP-CLI | `wordpress:cli-php8.3`, run as uid 33 so it matches the web container |
| Language and timezone | `sv_SE`, `Europe/Stockholm` |
| Debug | `WP_DEBUG` enabled |
| Plugin mount | `./plugin` → `wp-content/plugins/foreningsplugin` |
| Mail capture | Not installed. Do not send real mail from this lab |
| Node on the host | Available. Not required for the current placeholder plugin |
| Composer in the containers | Not installed |
| Snapshots | `make snap name=...` and `make restore name=...` dump the database and `uploads`. `ren-install` is the clean baseline |

Local credentials live only in `.env`. Snapshots stay on the machine and are gitignored because a database dump can contain personal data once real testing starts. Use synthetic data only.

The placeholder plugin shows an admin screen so the mount can be checked. It is not the product structure in `docs/PLUGIN_ARCHITECTURE.md`.
