# Project state at handoff — 2026-09-24

## Current phase

Implementation and product hardening. This is not a claim that the plugin is production-ready.

The sections below keep the original handoff. A later update records what has since been implemented.

## Current product shape

The plugin is a self-hosted WordPress association-management system for small and medium-sized nonprofit associations.

Core MVP direction:

**People → Membership → Board → Meetings → Agenda → Notes → Decisions → Minutes → Adjustment/Finalization → PDF/Print → Signed hard copy → Archive → Publication**

## Newly elevated core feature: meeting workspace

Meeting support is not just an archive.

The product should help the secretary/board:
- prepare reusable agendas/templates
- take notes inside agenda items during the meeting
- register structured decisions and action items
- generate a deterministic minutes draft
- review/adjust/finalize the minutes
- print an A4 hard copy
- export a server-generated PDF
- provide signature lines
- upload a scanned signed copy
- preserve finalized revisions historically

Annual-meeting templates must be configurable according to the association's own bylaws; no single Swedish legal template should be hardcoded as universally correct.

## Implementation gate

Original handoff instruction:

Cursor's first job is to complete the design baseline described in `CURSOR_START_PROMPT.md`.

Do not start broad feature implementation until that baseline has been reviewed/approved.

## Update after repository setup — 2026-09-24

A local Docker lab exists and is inventoried in `docs/16_LAB_INVENTORY.md`. `plugin/foreningsplugin.php` is only a lab placeholder.

A proposed design baseline now exists under `docs/` and `adr/`. On 2026-09-24 the owner locked five points: board assignments require membership, the signed copy is the archival original, finalizing minutes is a setting, retention defaults to 5 years, and the license is GPL-2.0-or-later.

The rest of the baseline stands as the accepted working design. At this point in the handoff, feature implementation had not started.

## Update after implementation — 2026-09-24

Implementation has started. The current database schema version is 16.

Implemented areas:

- association profile
- membership and person model, including ordinary, youth, family and company memberships
- board assignments and membership-coverage rules
- meetings, agenda, notes, decisions, minutes, PDF, signed copy and publication
- documents and private storage
- public blocks
- privacy export, erase and retention
- schema migrations through version 16
- members admin UX v1: list, detail, typed create flows, guardians, protected identity display and membership history
- board admin UX v1: current board, upcoming changes, history, replacement, multi-holder roles, and ending an assignment without deleting it
- meetings admin UX v1: operational overview and a single meeting workspace for preparation, capture, and minutes
- overview / dashboard UX v1: capability-aware association work overview for attention, counts, meetings, board, tasks, and recent documents
- member account provisioning v1: an active individual member with a usable email can receive a linked WordPress subscriber login. A missing WordPress user stays a broken link until an officer clears it. Member-only documents require a live WordPress user, an explicit Person link, and active individual coverage
- Mina sidor v1: a read-only Gutenberg block shows the logged-in person's own details, effective membership coverage, member documents while the membership is active, a privacy summary, and the WordPress account. Profile editing, guardian access, and member self-service requests are not implemented
- association setup v1: Settings lets `manage_association` reorder built-in board roles and meeting types and add custom ones. Built-in slugs and labels stay system definitions. A custom role's name and holder rule lock once a board assignment uses it. A custom meeting type's name locks once a meeting or template uses it. Order stays editable. Roles and meeting types are not deleted. Schema 16 is unchanged. Profile, retention, and minutes settings remain their existing pages, linked from Settings. The Association menu uses the navigation-only capability `access_association`, so settings stay reachable without `view_members`
- decisions admin UX v1: Association → Decisions lists decisions recorded in meetings. The meeting decision remains the only record. The default view is open follow-up, with the source meeting and agenda. Done means follow-up is complete. Follow-up can change after minutes are finalized without changing the finalized revision. Wording, deadline, responsible person, and deletion stay in the meeting workspace. Schema 16 is unchanged
- tasks admin UX v1: Association → Tasks lists action items recorded in meetings. The meeting action item remains the only record. The default view is open tasks, with the source meeting and agenda. Overdue means the task is open and the due date is before today. Status can be marked done or reopened. That can make an open minutes draft stale, and it leaves a finalized revision unchanged. Task text, assignee, due date, and deletion stay in the meeting workspace. Schema 16 is unchanged
- fresh ZIP install of 0.1.0 was validated on a separate WordPress, not the development lab. Activation migrated schema 0 to 16, and a small synthetic association flow including minutes PDF worked from the installed archive. That is package and fresh-install validation, not a production-ready claim. The same install showed WordPress 6.7+ warning that association translations were loaded before `init`. The plugin now loads its translations on `init`. Schema 16 is unchanged

Some product decisions remain provisional, including the migration policy and personal-identity encryption. Schema 16 is the current schema, not a claim that every earlier design note is locked.

## Planned product area — setup, help, guides, bulletins

**First-run Setup Wizard v1 is implemented** (ADR-0023). Local options `assoc_setup_version`, `assoc_setup_step`, and `assoc_setup_redirect_pending` track completion and first-run redirect. Schema remains 16. Plugin version remains 0.1.0.

Wizard markup uses one pattern on every step: a single primary form plus sibling Back/Skip forms via `form=` buttons. Nested forms previously broke Save and continue on Minutes and Privacy; that is fixed. See `docs/10_ADMIN_UX.md` and `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md`.

Help & Guides, contextual help, and optional registered-install communications remain proposal-only in `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` and `OPEN_QUESTIONS.md` (Q40–Q45). They are not implemented and not LOCKED.

## Lab stacks (in-repo)

Three Docker labs are documented in-repo:

1. **Bind-mount development lab** — root `docker-compose.yml` / `make up` / `make install` (plugin source mounted).
2. **Clean WordPress baseline** — `labs/wordpress-clean/` / `make clean-lab-*` (named volumes only; snapshot `wordpress-clean-before-foreningsplugin`; no foreningsplugin). See `labs/wordpress-clean/README.md` and `docs/17_RELEASE_PACKAGING.md`.
3. **Release-test** — `docker-compose.release-test.yml` / `make release-test` (fresh ZIP install on :8090).

An older outside-repo copy of the clean stack may still exist at `/home/ricande/projects/wordpress-clean/`; the canonical definition is now under `labs/wordpress-clean/`.
