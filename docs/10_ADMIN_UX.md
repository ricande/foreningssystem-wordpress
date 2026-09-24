# Admin UX requirements

## Principle

The primary users are association officers, not WordPress developers.

Do not expose internal data-model complexity unnecessarily.

## Proposed navigation

Association
- Overview
- Members
- Board
- Meetings
- Decisions
- Documents
- Settings

Future:
- Activities
- Membership fees
- Mail/recipient selection

## Overview

Show association work, not WordPress technical status.

Examples:
- active members
- current board
- next meeting
- last finalized meeting
- open decisions
- overdue action items
- latest documents

## Member flow

Critical tasks:
- add person/member
- edit contact details
- change membership status
- end membership
- view history
- link WordPress account where needed
- export/import where authorized

Dangerous actions must distinguish:
- end membership
- anonymize
- erase/delete

## Board flow

Key task:
“Replace treasurer without destroying history.”

UI should make start/end/term obvious.

## Meeting flow

### Before
- choose type/template
- date/time/place
- participants
- agenda

### During
The meeting page should support rapid note taking:
- agenda visible
- current item clear
- notes inline
- add decision inline
- add action inline
- move to next item with minimal friction

Avoid forcing the secretary through many full-page admin forms.

### After
- review generated minutes
- edit draft
- send/mark for adjustment
- finalize/adjust
- print
- export PDF
- upload signed copy
- publish if allowed

## Destructive/irreversible actions

Use explicit language.

Examples:
- “Finalize this minutes revision”
- “Create correction revision”
- “End membership”

Avoid vague generic “Save” for state transitions with legal/historical consequences.
