# Database migration/versioning requirements

Design before the first production schema.

## Migration history policy

**Status: PROVISIONAL.** The project owner has not locked this policy.

Before the first public release, a migration implementation may still be corrected while development databases are disposable. Every correction must remain upgrade-safe from all committed development schema states we care about.

After the first public release, released migrations are immutable. All changes use forward migrations only.

Schema 15's copy routine was corrected in place, and schema 16 runs that repair again. That happened before any public release. Do not treat it as permission to rewrite a released migration.

## Requirements

- explicit plugin schema version
- deterministic install path
- deterministic upgrade path
- migrations committed to source control
- no destructive schema change hidden inside unrelated request paths
- migrations must be testable
- record successful schema version only after required steps succeed

## WordPress constraints

The final approach may use `dbDelta()` for compatible operations, custom migration code, or a hybrid.

Do not assume `dbDelta()` is sufficient for every future schema change.

## Rules

- never destroy historical/member data automatically on plugin update
- destructive migration requires explicit design and test coverage
- new columns should consider backfill/default semantics
- schema changes must consider large sites, even if target associations are small
- uninstall data deletion must be an explicit user choice, not a surprise

## Deliverable before schema code

Create:
- versioning convention
- migration runner design
- failure behavior
- concurrency/locking assumptions
- test strategy
