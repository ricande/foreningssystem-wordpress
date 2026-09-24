# Prompt to give Cursor at project start

You are the primary implementation agent for this WordPress association-plugin project.

The project owner expects you to do substantial engineering work, but you must not skip the product/domain design phase.

## Start by reading

- `AGENTS.md`
- `DECISIONS.md`
- all files under `docs/`
- `OPEN_QUESTIONS.md`

Treat LOCKED decisions as constraints.

## First task — no feature implementation yet

Produce a design-baseline review in the repository.

Create or refine:

1. `docs/GLOSSARY.md`
2. `docs/DOMAIN_MODEL.md`
3. `docs/MEETING_STATE_MACHINE.md`
4. `docs/PRIVACY_MODEL.md`
5. `docs/CAPABILITY_MATRIX.md`
6. `docs/DATA_STORAGE_PLAN.md`
7. `docs/PLUGIN_ARCHITECTURE.md`
8. `docs/TESTING_PLAN.md`
9. ADRs under `adr/` for decisions that materially constrain implementation.

For each open decision:
- identify the alternatives
- explain the consequences
- recommend one
- do not silently convert a recommendation into a locked product decision

Pay particular attention to:
- Person vs Membership
- board history
- meeting state transitions
- minutes revision/finalization
- private Media Library files
- server-side PDF generation on ordinary WordPress hosting
- WordPress capabilities
- privacy exporter/eraser integration
- custom tables vs CPTs
- migration/versioning strategy

## WordPress standards

Use current official WordPress guidance as the primary external technical reference.

Security fundamentals:
- validate input
- sanitize where appropriate
- escape output
- capability checks
- nonce/CSRF protection
- explicit REST permission callbacks
- prepared SQL
- least privilege

Do not touch WordPress core.

## Cursor behavior

- Keep changes focused.
- Report unexpected existing changes before modifying them.
- Do not add AI/co-author trailers.
- Do not tag or release.
- Do not introduce mandatory SaaS.
- Do not add telemetry.
- Do not put secrets in the repository.

## VM

If a WordPress lab VM is available:
1. inventory it using `docs/14_VM_LAB.md`
2. document the actual environment
3. do not change production-like settings blindly
4. create or confirm a rollback/snapshot path
5. use synthetic data only

## Completion report for the first task

Return:
- files created/changed
- major recommended decisions
- unresolved decisions requiring owner input
- risks discovered
- exact test/validation performed
- git status
- commit hash only if the owner explicitly asked you to commit

Do not begin full plugin implementation until the design baseline is approved.
