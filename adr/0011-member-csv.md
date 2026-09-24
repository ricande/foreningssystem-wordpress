# ADR-0011: Member CSV is one row per membership period

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The treasurer can export members (`export_members`) and someone who may edit members can bring a list in. Email is not unique. A membership number is unique. Historical periods must survive a round trip. The file must not link WordPress accounts or rewrite a period that is already stored.

## Options considered

### Option A — One row per person, latest period only

Pros:
- Short files.

Cons:
- A second period disappears on export.
- Import would have to guess which period to update.

### Option B — One row per membership period, semicolon-separated UTF-8

Pros:
- History is visible.
- Swedish Excel opens a semicolon file without splitting on commas inside names.
- An existing membership number is skipped, so importing the same file again changes nothing.
- A new period is attached to the one person who already has that email. An empty email, or more than one match, creates a new person or rejects the row.
- Cells that start with `=`, `+`, `-`, or `@` are prefixed on export so a spreadsheet does not treat them as formulas.

Cons:
- A person with no membership is absent from the file. They remain in the member list.
- Import does not rename a person, change a stored period, or set `wp_user_id`.

## Decision

Use option B. Export requires `export_members`. Import requires `edit_members`. The columns are `first_name`, `last_name`, `email`, `person_status`, `membership_number`, `membership_type`, `membership_status`, `started_on`, `ended_on`. An ended period has an end date. Any other status has none. A deceased person in a new row cannot have an open period.

## Consequences

Positive:
- The treasurer can take the list out. An administrator can bring new periods in without touching history.

Negative/tradeoffs:
- Officers who only have the default secretary or chair bundle can neither export nor import. That matches the existing capability split.

## Revisit triggers

- The association wants to correct a stored period from a file.
- A second identifier, other than email, is needed to attach a period to a person.

Email is not an identity key. A blank email always creates a new person. More than one person with the same email rejects the row. A changed email does not update the stored person; the row is a new person unless the membership number already exists, in which case the row is skipped. The file has no stable person id, so a round trip cannot rename or re-address someone. That limit stays until the owner chooses an identifier.
