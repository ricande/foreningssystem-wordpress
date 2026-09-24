# ADR-0007: Schema version advances only after a successful migration

**Status:** Proposed  
**Date:** 2026-09-24

## Context

`docs/13_MIGRATIONS.md` requires an explicit version, a deterministic install, a deterministic upgrade, and no destructive surprise. `dbDelta()` is not enough for every future change.

## Options considered

### Option A — Call `dbDelta()` on every request

Pros:
- WordPress documents this pattern. New tables appear quickly.

Cons:
- Front-page requests become migration runners.
- `dbDelta()` does not express data backfills, renames, or partial failure well.
- A failure can repeat on every view.

### Option B — A versioned migration list runs from activation, WP-CLI, and an admin notice

Pros:
- Install and upgrade share one code path.
- The stored version changes only after the steps succeed.
- Tests can run the list against an empty database and an older version.
- A MySQL lock or an option lock avoids two admins migrating at once.

Cons:
- Someone must open wp-admin or run WP-CLI after an update. An admin notice covers the dashboard case.
- The team writes migration classes instead of only a schema file.

## Decision

Propose option B. Use `dbDelta()` inside a migration when it is creating or aligning tables. Use explicit SQL for changes `dbDelta()` handles poorly. The option name `assoc_schema_version` is provisional, like the table prefix.

Uninstall does not drop tables unless the officer opts in on the uninstall screen. Update never drops member or minutes data.

## Consequences

Positive:
- A failed migration leaves the old version number, so the next attempt retries.
- History is not tied to a request that also renders a public page.

Negative/tradeoffs:
- The plugin can be updated on disk before the schema is ready. Screens must refuse domain writes until the migration succeeds, and say why.

## Revisit triggers

The migration set grows large enough that associations need a background runner. That can be added without changing the version rule.
