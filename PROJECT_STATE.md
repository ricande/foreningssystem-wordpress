# Project state at handoff — 2026-09-24

## Current phase

Product definition and architecture design. No implementation has been approved yet.

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

Cursor's first job is to complete the design baseline described in `CURSOR_START_PROMPT.md`.

Do not start broad feature implementation until that baseline has been reviewed/approved.

## Update after repository setup — 2026-09-24

A local Docker lab exists and is inventoried in `docs/16_LAB_INVENTORY.md`. `plugin/foreningsplugin.php` is only a lab placeholder.

A proposed design baseline now exists under `docs/` and `adr/`. Those recommendations are not locked product decisions. Implementation still waits for owner approval.
