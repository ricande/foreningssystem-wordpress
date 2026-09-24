# ADR-0010: The association profile is site options

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The MVP needs one association name, an optional organization number, address and contact details, a language, a logo reference, and the start of the membership year. `DECISIONS.md` still treats one association per WordPress installation as provisional. `docs/DATA_STORAGE_PLAN.md` already recommends options for this object. Membership periods stay date ranges on each membership. The profile must not become a second calendar that rewrites those dates.

## Options considered

### Option A — One custom table row

Pros:
- Looks like the other association tables.

Cons:
- A single row needs the same migrations, prefixes, and empty-install behavior as a person or a meeting.
- The object has no history and no per-row privacy erasure.

### Option B — One serialized option

Pros:
- One key to read.

Cons:
- Adding a field later rewrites the whole blob.
- A corrupt value hides every other field.

### Option C — One option key per field

Pros:
- Matches the retention years option and the role-bundle option.
- A bad language or a bad membership-year start falls back on its own.
- The logo is a Media Library attachment id. The file stays in WordPress.

Cons:
- Several keys must be saved together. A failed save can leave a mix until the next successful save. The admin form saves all fields in one request.

## Decision

Store the profile as separate option keys under the `assoc_profile_` prefix. The membership year is a month and a day, default 1 January. A date belongs to the year that started on that month and day and ends the day before the next start. February 29 is not a start day. The language is `sv` or `en`. An empty profile is valid, so activation does not require a setup wizard. Changing the profile requires `manage_association`.

The setting does not split, end, or renumber memberships.

## Consequences

Positive:
- Blocks, minutes, and the overview can read one profile without a new schema version.
- The organization number is association data, not a person's identifier.

Negative/tradeoffs:
- A second association on the same site is still out of scope.
- The membership-year start is configuration until a later feature uses it to suggest a period. It does not answer how a term on a board assignment is labeled.

## Revisit triggers

- The product allows more than one association per installation.
- Membership fees need a closed accounting year that is not the membership year.
