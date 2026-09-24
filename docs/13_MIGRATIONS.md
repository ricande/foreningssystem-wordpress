# Database migration/versioning requirements

Design before the first production schema.

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
