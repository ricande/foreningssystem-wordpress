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

Implementation has started. The current database schema version is 17.

Implemented areas:

- association profile
- membership and person model, including ordinary, youth, family and company memberships
- board assignments and membership-coverage rules
- meetings, agenda, notes, decisions, minutes, PDF, signed copy and publication
- documents and private storage
- public blocks
- privacy export, erase and retention
- schema migrations through version 17
- members admin UX v1: list, detail, typed create flows, guardians, protected identity display and membership history
- board admin UX v1: guided board wizard (overview, one task at a time, confirm, separate history view) over the same BoardService rules for replacement, multi-holder roles, ending, and cancelling scheduled changes without deleting history
- meetings admin UX v1: operational overview and a single meeting workspace for preparation, capture, and minutes. A guide copies a system starting point into an association-owned meeting template: check the original items, then add and reorder headings. The annual-meeting starting point lists the usual annual-meeting items. Creating a meeting copies the saved template onto the meeting. Schema 17 is unchanged
- overview / dashboard UX v1: capability-aware association work overview for attention, counts, meetings, board, tasks, and recent documents
- member account provisioning v1: an active individual member with a usable email can receive a linked WordPress subscriber login. A missing WordPress user stays a broken link until an officer clears it. Member-only documents require a live WordPress user, an explicit Person link, and active individual coverage
- Mina sidor v1: a read-only Gutenberg block shows the logged-in person's own details, effective membership coverage, member documents while the membership is active, a privacy summary, and the WordPress account. Profile editing, guardian access, and member self-service requests are not implemented
- association setup v1: Settings is a hub for `manage_association`. Focused sub-screens cover association profile, board roles, meeting types, minutes locking, minutes publication, and retention. Built-in board role and meeting type slugs and labels stay system definitions. A custom role's name and holder rule lock once a board assignment uses it. A custom meeting type's name locks once a meeting or template uses it. Order stays editable. A built-in role or meeting type cannot be removed. An unused custom role or meeting type can. A custom role selected to finalize or publish minutes cannot be removed until that permission is changed (ADR-0027). Profile, retention, and minutes permission screens no longer have their own Association submenu entries; old page slugs redirect into Settings sections. Schema 16 is unchanged. The Association menu uses the navigation-only capability `access_association`, so settings stay reachable without `view_members`
- decisions admin UX v1: Association → Decisions lists decisions recorded in meetings. The meeting decision remains the only record. The default view is open follow-up, with the source meeting and agenda. Done means follow-up is complete. Follow-up can change after minutes are finalized without changing the finalized revision. Wording, deadline, responsible person, and deletion stay in the meeting workspace. Schema 16 is unchanged
- tasks admin UX v1: Association → Tasks lists action items recorded in meetings. The meeting action item remains the only record. The default view is open tasks, with the source meeting and agenda. Overdue means the task is open and the due date is before today. Status can be marked done or reopened. That can make an open minutes draft stale, and it leaves a finalized revision unchanged. Task text, assignee, due date, and deletion stay in the meeting workspace. Schema 16 is unchanged
- privacy identity rule: a WordPress export or erase request resolves to one Person. A verified `wp_user` link decides on its own. An email address several people share, such as a family address, identifies nobody in particular, so nothing is exported and nobody is anonymized. WordPress then reports that no data was found; an explicit message about the shared address is not implemented
- distributed information: the GitHub README and a user-oriented `plugin/readme.txt` tell the same story in the same order — what the plugin is, status, the development warning, features, installation, privacy and access, documentation, development, license. Both state that 0.1.0 is an early development build that is not for production or live association data. The readme travels inside the plugin ZIP, and the packaging contract fails if it is missing, names another version, or has lost the warning
- fresh ZIP install of 0.1.0 was validated on a separate WordPress, not the development lab. Activation migrated schema 0 to 16, and a small synthetic association flow including minutes PDF worked from the installed archive. That is package and fresh-install validation, not a production-ready claim. The same install showed WordPress 6.7+ warning that association translations were loaded before `init`. The plugin now loads its translations on `init`. Schema 16 is unchanged

Some product decisions remain provisional, including the migration policy and personal-identity encryption. Schema 17 is the current schema, not a claim that every earlier design note is locked. Schema 17 only inserts the built-in board-member role when that slug is missing (ADR-0028).

## Planned product area — setup, help, guides, bulletins

**First-run Setup Wizard v1 is implemented** (ADR-0023). Local options `assoc_setup_version`, `assoc_setup_step`, and `assoc_setup_redirect_pending` track completion and first-run redirect. Setup version remains 1. Plugin version remains 0.1.0. The board step includes the built-in board-member role, which several people may hold and which cannot be renamed or removed (ADR-0028).

**Board Admin Wizard v1 is implemented** (ADR-0024). Association → Board is a guided flow (Get started / Change the board) with one task per run and a separate history view. BoardService semantics and schema 16 are unchanged.

Wizard markup uses one pattern on every step: a single primary form plus sibling Back/Skip forms via `form=` buttons. Nested forms previously broke Save and continue on Minutes and Privacy; that is fixed. See `docs/10_ADMIN_UX.md` and `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md`.

Help & Guides, contextual help, and optional registered-install communications remain proposal-only in `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` and `OPEN_QUESTIONS.md` (Q40–Q45). They are not implemented and not LOCKED.

## Update after hardening batch 2 — 2026-09-26

Four storage and concurrency findings are fixed. Plugin version stays 0.1.0 and schema stays 16.

- **Signed copies.** Uploading a signed scan holds the revision through a MySQL advisory lock, and the replacement marks every copy that is still current as replaced in one statement. A revision keeps at most one current signed copy even if two officers upload at the same time, and a revision that already carried two current copies collapses to one on the next upload. A held revision answers with `SignedCopyBusy` and the meeting screen reports it as a temporary state.
- **Private storage path.** Whether the private directory sits under the web root is decided by the resolved path once the directory exists, so a symlink or a `..` segment cannot present a directory inside the web root as one outside it. A path that does not exist yet keeps its spelled-out placement, and the fallback and warning model are unchanged.
- **Storage root state.** `assoc_private_storage_root` records the active root and `assoc_private_storage_earlier_roots` records the roots that may still hold files (ADR-0025). Changing the private directory, in either direction, moves the files it can move, never overwrites a name that exists with different bytes, and keeps a root that still holds files or is unreachable in the recorded state. Reads and deletes follow the recorded roots; writes only go to the active root.
- **Minutes PDFs.** A regenerated minutes PDF removes the file it replaced only after the revision row has been read back pointing at the new file. A failed write, a failed row update, or a row that cannot be read back leaves the working PDF in place. Private files are written under a working name and moved into place with one rename.

Not part of this batch: retention and guardian policy, personal-identity-number encryption.

## Update — member spreadsheet import — 2026-09-29

Association → Members can import a foreign member spreadsheet (ADR-0026). The plugin's own member file and the structured member file are unchanged. Plugin version stays 0.1.0. Schema stays 16. No migration.

The wizard parses UTF-8 CSV (comma or semicolon, quotes, Swedish characters), lets an officer map columns onto the person and membership fields that exist, and validates every row before anything is saved. More than 2 000 data rows is refused, and the admin message states that limit. Preview does not write. Confirm reads the stored file again and creates people and memberships through the existing repositories, one transaction per row.

Phone, street address, postal code, and city are not imported, because Person has no such fields. A personal identity number is not imported. Its storage stays unencrypted in its own table, as in ADR-0021. Company rows and extra family participants stay in the structured file. The wizard does not link a WordPress user. An existing membership number, a repeated number, or a shared or repeated email is skipped and explained. The same name is not treated as the same person.

## Update — unused custom structure in setup — 2026-09-29

An officer can remove a custom board role or meeting type that nothing uses yet, from the setup wizard and from Settings (ADR-0027). Built-in rows stay. A board assignment, including closed history, blocks removal of that role. A meeting or a meeting template blocks removal of that meeting type. A custom role selected to finalize or publish minutes is also in use until that permission is cleared, so the minutes setting does not keep a slug after the role is gone.

The minutes step, and the matching Settings screens, list custom board roles beside the four association roles for both permissions. The choice grants the system permission. It does not appoint who adjusts one meeting. Plugin version stays 0.1.0. Schema stays 16. No migration.

Future hardening, recorded in ADR-0027 and not built here: `used()` and `remove()` share one transaction, but board assignments, meetings, and meeting templates reference the structure row by an index, not a foreign key. A concurrent insert can point at a row that has just been removed. The minutes policy in `wp_options` has the same theoretical gap.

## Planned product area — sections and departments

**FUTURE / NOT IMPLEMENTED / PROPOSAL.** Not part of the implemented list above. No tables, screens, capabilities, or document types exist for this.

Associations may later record optional sections or departments inside the one association, with activity reports, meeting material, an internal budget, and simple economic follow-up. That money tracking is not legal bookkeeping. A person who looks after one section does not thereby administer the association. The proposal is `docs/19_SECTIONS.md`. Open questions are Q46–Q57 in `OPEN_QUESTIONS.md`. Plugin version stays 0.1.0. Sections are not part of schema 17. Do not start an implementation from this note.

## Lab stacks (in-repo)

Three Docker labs are documented in-repo:

1. **Bind-mount development lab** — root `docker-compose.yml` / `make up` / `make install` (plugin source mounted).
2. **Clean WordPress baseline** — `labs/wordpress-clean/` / `make clean-lab-*` (named volumes only; snapshot `wordpress-clean-before-foreningsplugin`; no foreningsplugin). See `labs/wordpress-clean/README.md` and `docs/17_RELEASE_PACKAGING.md`.
3. **Release-test** — `docker-compose.release-test.yml` / `make release-test` (fresh ZIP install on :8090).

An older outside-repo copy of the clean stack may still exist at `/home/ricande/projects/wordpress-clean/`; the canonical definition is now under `labs/wordpress-clean/`.
