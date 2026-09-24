# ADR-0004: Finalized minutes are immutable snapshots

**Status:** Proposed  
**Date:** 2026-09-24

## Context

Finalized minutes must not change because someone later edits a note, replaces a treasurer, or marks an action item done. Associations also need a way to correct a real mistake.

## Options considered

### Option A — The minutes always render live from the meeting tables

Pros:
- One source of truth. No copy.

Cons:
- Contradicts the locked rule against silent changes to finalized minutes.
- A later data fix rewrites history.

### Option B — Freeze a revision payload and body. Corrections are new revisions

Pros:
- The approved text stays.
- Follow-up status can still change on the live action item.
- A signed scan can point at a specific revision.

Cons:
- Two copies exist: live rows and the snapshot. Officers must understand that regenerating a draft is explicit.
- Storage grows by revision, which is small for this product.

## Decision

Propose option B. The state machine in `docs/MEETING_STATE_MACHINE.md` is the recommended workflow. "Finalized" means immutable product text, not a legal certificate for every jurisdiction.

Publication is a visibility flag. It is not a further edit.

## Consequences

Positive:
- Tests can prove that later edits do not change a finalized body.
- PDF generation has a stable input.

Negative/tradeoffs:
- The UI needs "create correction" rather than a normal save on a locked revision.
- Decision text inside the snapshot can diverge from a later live correction until a new revision exists. That divergence is intentional.

## Revisit triggers

The owner wants the live decision text to remain the only copy and accepts that finalized minutes change. That would conflict with a locked decision, so it needs an explicit change to `DECISIONS.md`.
