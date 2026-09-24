# ADR-0001: Person and membership are separate

**Status:** Accepted  
**Date:** 2026-09-24

## Context

The locked product rule is that a member is not a `wp_user`. The open question is whether a person and a membership are also separate, and whether officers can exist without an active membership.

## Options considered

### Option A — One member row that is both the person and the membership

Pros:
- Fewer tables and a simpler first screen.

Cons:
- Re-entry overwrites history or needs awkward extra columns.
- A non-member auditor does not fit.
- Privacy erasure and "end membership" collapse into one operation.

### Option B — Person and membership periods are different objects

Pros:
- Matches former members, re-entry, and people who are known but not members.
- End membership, anonymize, and erase can be different actions.
- Board assignments point at a person, not at a status flag.

Cons:
- The member screen must explain two concepts without dumping the data model on the officer.
- Overlap rules need tests.

## Decision

Accept option B. A person and a membership period stay separate.

The owner locked a further rule on 2026-09-24: a board assignment requires an active membership that covers the assignment dates. That includes auditor and election-committee roles, because they are board roles in this model. There is no setting to waive it.

Ending a membership ends any open assignment on the same date.

## Consequences

Positive:
- History and privacy stay expressible.
- Public blocks can show a role without reading private contact fields.

Negative/tradeoffs:
- The first member screen is a person form plus a membership section, not a single flat record.

## Revisit triggers

The owner decides that overlapping membership periods must be representable. Or an import contains officers who were never members, which the locked rule cannot store without an explicit exception.
