# ADR-0012: A meeting template is copied when the meeting is created

**Status:** Proposed  
**Date:** 2026-09-24

## Context

Officers reuse the same agenda headings. `docs/DATA_STORAGE_PLAN.md` treats templates as configuration in custom tables, not as public posts. A Swedish legal annual-meeting agenda must not be hardcoded. A template change after a meeting exists must not rewrite that meeting.

## Options considered

### Option A — Store the template id on each agenda item

Pros:
- The meeting can be refreshed from the template.

Cons:
- Refreshing would rewrite a meeting that may already have notes.
- The template becomes a second source for the agenda.

### Option B — Copy the headings once, then forget the template

Pros:
- The meeting agenda is ordinary agenda items.
- Editing or deleting the template leaves every existing meeting alone.
- The association writes its own headings. The plugin only seeds meeting types.

Cons:
- A correction to a template does not flow into meetings already created. The secretary edits those agendas directly.

## Decision

Use option B. Schema version 13 adds `assoc_meeting_template` and `assoc_meeting_template_item`. Creating, editing, and applying a template requires `manage_meetings`. Applying it is allowed only for a planned meeting whose agenda is still empty, and only when the template belongs to the meeting's type.

## Consequences

Positive:
- Reused headings are data.
- Finalized minutes stay independent of later template edits, because the agenda was already copied.

Negative/tradeoffs:
- There is no "update meetings from this template" action.

## Revisit triggers

- The association wants a template to include suggested decision text. That text would still be a copy, not a live link.
