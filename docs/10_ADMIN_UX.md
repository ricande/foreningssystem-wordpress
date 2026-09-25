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
- Tasks
- Documents
- Settings

Decisions is a cross-meeting view of decisions already recorded in meetings. The meeting decision stays the only record. The screen opens on follow-up that is still open. Each row shows the source meeting title, date, and status, and the agenda number and title when the decision belongs to an agenda item. A decision without an agenda item stays visible as a meeting-level decision. Overdue means the follow-up is still open and the deadline is before today. Done means the follow-up is complete. It does not revoke the decision or change the minutes. Follow-up can be marked done or reopened after the minutes are finalized, and the finalized revision body, payload, number, and visibility stay as they were. Wording, deadline, and the responsible person are changed in the meeting workspace, not on this screen.

Tasks is a cross-meeting view of action items already recorded in meetings. The meeting action item stays the only record. There are no standalone tasks. The screen opens on tasks that are still open. Each row shows the source meeting title, date, and status, and the agenda number and title when the task belongs to an agenda item. A task without an agenda item stays visible as a meeting-level task. Overdue means the task is still open and the due date is before today. Done means the task has been carried out. The task text, assignee, and due date stay in the meeting workspace. Marking a task done or reopening it can make an open minutes draft stale. It does not rewrite a finalized revision.

Future:
- Activities
- Membership fees
- Mail/recipient selection
- Help & Guides / optional message center — proposed only; see `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` (not implemented, not LOCKED)
- First-run Setup Wizard v1 — **implemented**; see ADR-0023 and Association → Get started / Settings → Run setup guide again

## Settings

Association → Settings is the entry point for association structure. It requires `manage_association`. A person who can edit the board or schedule meetings cannot redefine those structures through `manage_board` or `manage_meetings`.

The Association menu itself uses `access_association`. That capability only shows the menu. Someone who manages association settings can open the menu and Settings without `view_members`. Members, meetings, documents, and the board stay behind their own capabilities.

The screen links to the existing association profile, retention, minutes locking, and minutes publication pages. Those pages stay as they are. When setup is complete, Settings also links to **Run setup guide again**. Reopening the guide does not mark setup incomplete, hide menus, or reset domain data.

Board roles and meeting types can be reordered. The seeded built-in slugs stay fixed, and their labels stay plugin translations. An association can add its own roles and meeting types. The internal slug is created once and is not edited. A custom role name and holder rule become fixed once any board assignment uses the role. A custom meeting-type name becomes fixed once any meeting or meeting template uses it. Order can still change. Nothing in this screen deletes a role or a meeting type. Schema 16 is unchanged.

Decisions, activities, fees, and mailings are not part of this screen.

## First-run setup wizard

Fresh installs keep `assoc_setup_version` incomplete until Wizard v1 is finished. While incomplete, Association navigation shows **Get started** / **Kom igång** instead of the operational screens. Direct URLs keep their existing capability checks. The wizard is server-rendered WordPress admin HTML and reuses the same profile, structure, minutes-permission, and retention services as the ordinary Settings pages.

**Markup rule (all steps):** nested HTML `<form>` elements break **Save and continue**. HTML5 closes the outer form at the first nested `</form>`, so the primary submit can end up outside any form and do nothing. Minutes and Privacy hit this when Back/Skip were nested inside the save form (Skip could still work via surviving aux association). Every wizard step now uses one pattern: open one primary form (fields + primary submit); Back/Skip are `<button form="…">` controls that target hidden sibling forms emitted **after** the primary form closes — never nest Back/Skip forms inside the save form. Welcome has no Back; Association also omits Back (its only predecessor is Welcome). Membership and later steps keep Back.

Completing the wizard sets setup version `1`, restores the full Association menu, and shows a success notice on Overview. Next-step links for members, board, and the first meeting appear only when the current user has the matching action capability (`edit_members`, `manage_board`, `manage_meetings`). `manage_association` alone still shows the success message without an empty link list. Help & Guides is not part of this flow.

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

The board screen is a guided wizard, not one long page of every form at once.

**Overview** shows who sits now, upcoming changes, and coverage warnings (vacant single-holder roles when the board already has holders; scheduled successors). The primary CTA is **Get started** when the board is empty, otherwise **Change the board**. History is a separate view via **Show history**, not the middle of the start page. Board role definitions stay in Association Settings; the wizard only creates and changes assignments.

**Change / Get started** steps, one task at a time:

1. Choose task — Replace role | Add holder | End assignment | Cancel scheduled change (only tasks that currently apply)
2. Choose role — single-holder vs multi-holder made clear
3. Person and dates — same membership-coverage rules as before
4. Confirm — explicit consequence (current row may close; history preserved; scheduled cancel is not kept as history)
5. Done — back to overview

A single-holder role offers replacement for whoever is current today, including when that assignment already has an end date. If a successor is already scheduled, replace is unavailable until that plan is cancelled. A role that allows several holders offers add holder instead, and existing holders stay. Ending an assignment that has started asks for a date and confirmation; the earlier row remains. Cancelling a future assignment removes the plan without reopening the current holder. An open current assignment is shown as continuing until it is changed, not as a distant end date.

The wizard is server-rendered WordPress admin HTML. Navigation between steps uses links and GET forms; mutations POST through the existing admin-post handlers. Nested forms are avoided (same rule as the setup wizard).

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
