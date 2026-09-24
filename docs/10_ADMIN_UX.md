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

The board screen shows the current board first, then upcoming changes, history, and the actions that change it. A single-holder role offers replacement for whoever is current today, including when that assignment already has an end date. If a successor is already scheduled, the screen asks the officer to cancel that plan before adding another. A role that allows several holders offers another holder instead, and the existing holders stay. Ending an assignment that has started asks for a date and confirmation. The earlier row remains. A future assignment is shown as starting on its date, and cancelling it removes the plan without reopening the current holder. An open current assignment is shown as continuing until it is changed, not as a distant end date.

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
