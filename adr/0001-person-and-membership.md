# ADR-0001: Person and membership are separate

**Status:** Proposed  
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

Propose option B. Allow a board assignment without an active membership. Add an association setting later if an association wants to require membership for ordinary board roles. Do not require it in the schema.

## Consequences

Positive:
- History and privacy stay expressible.
- Public blocks can show a role without reading private contact fields.

Negative/tradeoffs:
- The first member screen is a person form plus a membership section, not a single flat record.

## Revisit triggers

The owner decides every officer must be an active member, with no exceptions for auditors or guests. Or a real association import cannot be represented without overlapping periods.
