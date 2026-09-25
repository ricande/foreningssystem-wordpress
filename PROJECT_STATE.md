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
- member account provisioning v1: an active individual member with a usable email can receive a linked WordPress subscriber login. A missing WordPress user stays a broken link until an officer clears it. Mina sidor and member self-service are not implemented

Some product decisions remain provisional, including the migration policy and personal-identity encryption. Schema 16 is the current schema, not a claim that every earlier design note is locked.
