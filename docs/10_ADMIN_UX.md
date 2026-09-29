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

Settings is a hub, not one long page. The landing screen links to focused sub-screens via `admin.php?page=foreningsplugin-settings&section=…`:

- Association profile (`section=profile`)
- Board roles (`section=board-roles`) — list, reorder, add, edit unused custom roles, and remove a custom role that nothing uses
- Meeting types (`section=meeting-types`) — list, reorder, add, rename unused custom types, and remove a custom type that nothing uses
- Lock minutes (`section=minutes-lock`)
- Publish minutes (`section=minutes-publish`)
- Retention (`section=retention`)
- Run setup guide again (opens the existing setup page; reopening does not mark setup incomplete, hide menus, or reset domain data)

Profile, retention, minutes locking, and minutes publication no longer appear as their own Association submenu items. Old bookmarks such as `page=foreningsplugin-profile` redirect into the matching Settings section. Each focused screen links back to the Settings hub.

Board roles and meeting types can be reordered. The seeded built-in slugs stay fixed, and their labels stay plugin translations. Board member is one of those roles. Several people may hold it, and that holder rule stays fixed with the name. An association can add its own roles and meeting types. The internal slug is created once and is not edited. A name that matches an existing role or meeting type, including a built-in English or Swedish label, is refused. A custom role name and holder rule become fixed once any board assignment uses the role. A custom meeting-type name becomes fixed once any meeting or meeting template uses it. Order can still change. A built-in role or meeting type cannot be removed. A custom role can be removed while no board assignment uses it and it is not selected to finalize or publish minutes. A custom meeting type can be removed while no meeting and no meeting template uses it. Removal is refused, with a stated reason, when that history or permission still points at the row. Schema 17 inserts the board-member role when that slug is missing. It does not change a table.

Decisions, activities, fees, and mailings are not part of this screen.

## First-run setup wizard

Fresh installs keep `assoc_setup_version` incomplete until Wizard v1 is finished. While incomplete, Association navigation shows **Get started** / **Kom igång** instead of the operational screens. Direct URLs keep their existing capability checks. The wizard is server-rendered WordPress admin HTML and reuses the same profile, structure, minutes-permission, and retention services as the ordinary Settings pages.

**Markup rule (all steps):** nested HTML `<form>` elements break **Save and continue**. HTML5 closes the outer form at the first nested `</form>`, so the primary submit can end up outside any form and do nothing. Minutes and Privacy hit this when Back/Skip were nested inside the save form (Skip could still work via surviving aux association). Every wizard step now uses one pattern: open one primary form (fields + primary submit); Back/Skip are `<button form="…">` controls that target hidden sibling forms emitted **after** the primary form closes — never nest Back/Skip forms inside the save form. Welcome has no Back; Association also omits Back (its only predecessor is Welcome). Later steps keep Back. Membership, Board, and Meetings are informational or optional-add steps: Back and Continue only, because Continue and Skip would do the same thing. Minutes and Privacy keep Skip, which advances without writing. A step that writes uses **Save and continue**. The Association step does not edit the logo; saving it reloads the canonical profile and keeps `logoAttachmentId`. The Complete step is a read-only summary (association name, organization number when set, language, membership-year start, membership kinds, board-role and meeting-type names, who may finalize and publish minutes, retention years). Finish still only writes setup completion state.

On the board and meeting steps, an unused custom role or meeting type can be removed at once. Built-in rows stay, including board member with several holders. Adding a role or meeting type whose name already exists is refused, and the wizard says that the name already exists. A custom role that a board assignment uses, or that is selected to finalize or publish minutes, stays until that reference is gone. A custom meeting type that a meeting or template uses stays. The minutes step lists the four association roles and any custom board role, for both permissions. The board-member seat is not listed there again, and neither is a custom role whose name repeats Secretary, Chair, Treasurer, or Board member. That choice grants the system permission. It does not appoint who adjusts one meeting. Completing the wizard sets setup version `1`, restores the full Association menu, and shows a success notice on Overview. Next-step links for members, board, and the first meeting appear only when the current user has the matching action capability (`edit_members`, `manage_board`, `manage_meetings`). `manage_association` alone still shows the success message without an empty link list. Help & Guides is not part of this flow.

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

Someone who may edit members can import a foreign spreadsheet from the member list. The steps are choose a CSV file, check the columns, preview, check problems, and confirm. Nothing is saved before confirm. A file with more than 2 000 data rows is refused, and the screen states that limit. The preview shows how many rows were read, how many are ready, possible duplicates, and errors, with the CSV row number. A personal identity number, phone number, or address column is left out. Company memberships stay in the structured member file. The plugin's own member file remains the separate exact-format import on the same screen.

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

The meetings screen is an operational overview: in-progress meetings first, then upcoming planned meetings by nearest date, then held meetings newest first. Each state offers the next action for that state, such as opening or starting a planned meeting, continuing or marking an in-progress meeting as held, and opening or reviewing minutes for a held meeting. An empty list asks an authorized officer to create the association's first meeting. Creating a meeting is its own step, opened from the overview. That form is not shown while a meeting template is being created or edited.

Creating a meeting asks for type, a saved association template, title, date, time, and place. A new template is a short guide: choose a starting point, check the original items to keep, then add headings and change their order. The annual-meeting starting point lists the usual annual-meeting items. The association meeting, board meeting, and empty starting points stay available in the same guide. The saved result is still one association-owned meeting template. A meeting gets its own copy. Later changes to a starting point do not change saved templates, and later changes to a saved template do not change meetings already created. Starting points are not stored as template rows.

The meeting page is one workspace. While the meeting is planned or held, the header shows title, type, date, time, place, and status, with one primary next action, and a link back to all meetings. Participants can be current board members, other members who are present, or another living person. Chair, secretary, and adjuster are optional functions for that meeting. The agenda is an ordered working list. While the meeting is in progress, the workspace follows the same graphical structure as the association setup guide: the meeting title at the top, then progress as item N of X with the numbered step strip, without the all-meetings link and without a mid-meeting mark-as-held control. The agenda and the participants are separate tabs. Continuing the meeting opens the agenda tab. The participant list and the form for adding someone present are on the participants tab. The current item's text field and buttons sit under the progress strip. Next saves the text when there is any and opens the following item. Back returns to the previous item. Continuing from the last agenda item opens **End meeting** with **Are you sure?** and Yes / No. Yes marks the meeting held with the same domain transition as before (notes, decisions, and tasks remain for minutes). No returns to the last agenda item and leaves the meeting in progress. A decision or a task can still be added on that item. After the meeting is marked held, the minutes section becomes the next step: create a deterministic draft, save a hand edit, regenerate only with confirmation when that would discard the edit, send the existing revision through review, and finalize it as a preserved revision. A correction is a new revision. Print, PDF, a privately stored signed copy, and publication stay separate actions. Finalizing does not publish, and uploading a signed copy does not publish it.

A child record can be changed only together with the meeting it belongs to. A crafted request that names one meeting and a participant, agenda item, note, decision, task, or minutes revision from another meeting is rejected, and the other meeting stays unchanged.

## Destructive/irreversible actions

Use explicit language.

Examples:
- “Finalize this minutes revision”
- “Create correction revision”
- “End membership”

Avoid vague generic “Save” for state transitions with legal/historical consequences.
