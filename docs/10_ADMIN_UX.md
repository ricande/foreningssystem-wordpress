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

## Settings

Association → Settings is the entry point for association structure. It requires `manage_association`. A person who can edit the board or schedule meetings cannot redefine those structures through `manage_board` or `manage_meetings`.

The screen links to the existing association profile, retention, minutes locking, and minutes publication pages. Those pages stay as they are.

Board roles and meeting types can be reordered. The seeded built-in slugs stay fixed, and their labels stay plugin translations. An association can add its own roles and meeting types. The internal slug is created once and is not edited. A custom role name and holder rule become fixed once any board assignment uses the role. A custom meeting-type name becomes fixed once any meeting or meeting template uses it. Order can still change. Nothing in this screen deletes a role or a meeting type. Schema 16 is unchanged.

Decisions, activities, fees, and mailings are not part of this screen.

## Overview

The Association screen is the officer's working overview. It shows what needs attention, then counts, meetings, the current board, open decisions and tasks, and recent documents. It does not describe whether the plugin is active.

Needs attention comes before the counts: a meeting in progress, overdue tasks, held meetings whose current minutes are missing, still a draft, or under adjustment, an upcoming board change, and the next planned meeting. A meeting stays in progress from its status, including when the scheduled start is already past. A held meeting is not treated as finalized minutes. Finalized minutes are the latest current finalized revision. Publication is separate.

Each section uses the same capability as its own screen. Member counts and the current board require `view_members`. Meetings, decisions, tasks, and minutes require `view_internal_meetings`. Documents require `view_board_documents` or `manage_documents`. The cards link to Members, Board, Meetings, a specific meeting, or Documents. The overview does not change those records itself.

An association with no members, board, meetings, or documents shows setup links for the steps the current user may perform. Active individual members and active memberships stay separate counts. Current board assignments are today only; a future assignment is an upcoming change. Documents have no created timestamp, so the recent list is the five highest ids. Overdue means an open task with a due date before today.

## Member flow

Critical tasks:
- add person/member
- edit contact details
- change membership status
- end membership
- view history
- link WordPress account where needed
- export/import where authorized

Member detail has a Member account section. An active individual member with a usable email normally receives a WordPress subscriber account. A known age under 18 does not. A missing birth date is not treated as proof of minority in this version; that is implementation behavior, not a legal conclusion. The section shows whether the account is linked, not created, or needs attention because the email is shared or already belongs to a WordPress user. An authorized officer can create the account, link an existing WordPress user after confirming that exact account, or unlink it. Unlinking does not delete the WordPress user. The plugin does not set or display a password. Changing the member's contact email does not change the WordPress account email. Ending the membership leaves the account in place; member-only access follows current membership coverage.

Dangerous actions must distinguish:
- end membership
- anonymize
- erase/delete

## Board flow

Key task:
“Replace treasurer without destroying history.”

The board screen shows the current board first, then upcoming changes, history, and the actions that change it. A single-holder role offers replacement for whoever is current today, including when that assignment already has an end date. If a successor is already scheduled, the screen asks the officer to cancel that plan before adding another. A role that allows several holders offers another holder instead, and the existing holders stay. Ending an assignment that has started asks for a date and confirmation. The earlier row remains. A future assignment is shown as starting on its date, and cancelling it removes the plan without reopening the current holder. An open current assignment is shown as continuing until it is changed, not as a distant end date.

## Meeting flow

The meetings screen is an operational overview: in-progress meetings first, then upcoming planned meetings by nearest date, then held meetings newest first. Each state offers the next action for that state, such as opening or starting a planned meeting, continuing or marking an in-progress meeting as held, and opening or reviewing minutes for a held meeting. An empty list asks an authorized officer to create the association's first meeting.

Creating a meeting asks for type, template, title, date, time, and place. Template headings are copied into that meeting. A later template change does not rewrite it, and a template is not a legally complete annual-meeting agenda. Template management sits in a secondary section.

The meeting page is one workspace. The header shows title, type, date, time, place, and status, with one primary next action. Participants show name, attendance, and duty. The agenda is an ordered working list. During an in-progress meeting the secretary can focus one agenda item and add a working note, a note marked for inclusion, a decision, or a task without leaving the page. After the meeting is marked held, the minutes section becomes the next step: create a deterministic draft, save a hand edit, regenerate only with confirmation when that would discard the edit, send the existing revision through review, and finalize it as a preserved revision. A correction is a new revision. Print, PDF, a privately stored signed copy, and publication stay separate actions. Finalizing does not publish, and uploading a signed copy does not publish it.

A child record can be changed only together with the meeting it belongs to. A crafted request that names one meeting and a participant, agenda item, note, decision, task, or minutes revision from another meeting is rejected, and the other meeting stays unchanged.

## Destructive/irreversible actions

Use explicit language.

Examples:
- “Finalize this minutes revision”
- “Create correction revision”
- “End membership”

Avoid vague generic “Save” for state transitions with legal/historical consequences.
