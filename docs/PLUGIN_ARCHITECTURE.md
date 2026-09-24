# Plugin architecture

Status: **proposed**. No package layout here is locked. The file `plugin/foreningsplugin.php` currently in the repository is a lab placeholder, not this architecture.

## Boundaries

Domain code does not call WordPress functions. WordPress adapters translate capabilities, nonces, db queries, cron, privacy hooks, and blocks into domain operations.

```text
plugin/
  foreningsplugin.php          bootstrap only
  src/Domain/                  entities, state transitions, invariants
  src/Application/             use cases such as FinalizeMinutes
  src/Infrastructure/Persistence/
  src/Infrastructure/WordPress/
  templates/                   admin and print views
  languages/
  tests/Domain/
  tests/Integration/
```

A use case receives already-authorized input or checks an authorization port. Templates escape on output. SQL stays in persistence implementations and uses `$wpdb->prepare`.

## Recommended platform baseline

Propose PHP 8.2 and WordPress 6.7 as the minimum, because the domain code wants typed properties and the lab already runs newer versions. ADR 0008 records this as a proposal. The lab's WordPress 7.1.2 and PHP 8.3 are the current development target, not the compatibility floor.

## Admin experience

Use the WordPress admin menu from `docs/10_ADMIN_UX.md`: Overview, Members, Board, Meetings, Decisions, Documents, Settings.

Server-rendered screens are the default. The meeting workspace may use a small script for inline notes, decisions, and moving to the next agenda item. That script posts to admin-ajax or REST with the same capability checks as the form. It is not a separate application and it does not become a required SPA framework.

Do not build the meeting UI as many full-page forms.

## Blocks

MVP blocks are dynamic server-rendered blocks:

- Current board
- Latest board meeting
- Latest minutes
- Document archive
- Member count

The saved block contains configuration only, such as how many meetings to show. It does not contain member emails or minutes HTML. Editor preview uses the same visibility rules as the front end. A user who cannot see board documents does not see them in a preview response.

Member-only output varies by viewer, so it must not be cached as one anonymous page fragment. Public blocks may use normal WordPress caching.

## PDF and print

Print is HTML with a print stylesheet. PDF is a server-side render of the same finalized snapshot. ADR 0006 recommends the contract and leaves the library for a spike. No PDF SaaS.

## Files

The Media Library stores bytes. The plugin stores the access rule and serves non-public files through an authenticated download handler. ADR 0005 describes the hosting limitation.

## Dependencies

Prefer PHP that ships with the plugin or WordPress. A PDF library is the likely first third-party dependency. It needs an ADR that covers license, maintenance, and what the plugin does if the library becomes unusable: print view still works.

Composer may be used for development and for that library. The WordPress.org package must vendor what it needs. No runtime call to Composer, npm, or an external update server is required for the association's site to function.

## Internationalization

Text domain and plugin slug are not locked. The repository is `foreningssystem-wordpress`. Swedish and English ship together. User-facing strings use WordPress i18n functions. Association content entered by officers is not translated by the plugin.

## Explicitly not in the first code structure

Fees, activities, a mail transport, payment providers, and a member portal do not get empty subsystems in the first bootstrap beyond reserved capability names.
