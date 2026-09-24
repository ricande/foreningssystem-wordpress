# ADR-0002: Board history is date-bounded assignments

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The product must answer who held an office on a given date after that person has been replaced. Annual meetings are one way assignments change, not the only way.

## Options considered

### Option A — A current-role flag on the person

Pros:
- Easy to show "the current board".

Cons:
- History is destroyed or pushed into unstructured notes.
- Two people cannot be compared across years.

### Option B — Assignments with start and end dates

Pros:
- "Who was treasurer on this date?" is a query.
- Replacing someone ends the old row and inserts a new one.
- A term label or source meeting can sit on the row without becoming the source of truth.

Cons:
- The UI must make the end date obvious so officers do not think the old treasurer was deleted.

## Decision

Propose option B. Dates are required for the query. A term name, membership year, or source meeting id are optional metadata. Applying election results is an explicit confirmation, not a side effect of finalizing minutes.

## Consequences

Positive:
- Current board is the set of assignments whose dates cover today.
- Public and admin views share that query.
- Placing an assignment uses the same continuous member coverage as the rest of the membership model. Adjacent effective intervals cover one assignment. A gap does not.

Negative/tradeoffs:
- Open-ended assignments need a clear "current" presentation so a missing end date is not a data error.

## Revisit triggers

Associations need overlapping holders of the same role as a normal case, such as two treasurers during a handover. The model can allow that later if the owner wants it. This proposal assumes one current holder per role unless the role itself is marked as allowing several people.
