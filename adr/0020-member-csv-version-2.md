# ADR-0020: Versioned member CSV

**Status:** Accepted  
**Date:** 2026-09-24

## Context

ADR-0011 describes one row per period. Family participants and organizations do not fit that file if every row must have a unique number.

## Decision

The treasurer export keeps the existing columns and does not contain personal identity numbers. A number that already exists is skipped, so a repeated import does not rewrite history.

A second file starts with `# foreningsplugin-members 2` and has `organization`, `membership`, `period` and `participant` rows, imported in that order. Participant rows carry the participation start and end. The current export always writes the participant start date. A round trip uses that date and does not depend on a fallback.

A participant row in this version that includes the start column but leaves it empty is rejected. The import does not invent a start date for that row.

A row from a file that has no start column at all is compatibility input from before those columns. Its start is the earliest period on that membership. That fallback is not the normal exported form.

The file can add a later period to an existing number. Importing a participant uses the same admission rules as adding a participant in the application: no overlapping individual-member coverage, a company contact is not a member, and a deceased person cannot gain a new member interval. An identical participant interval is skipped so a repeated import does not add a second row. It also excludes personal identity numbers. A protected identity export is not provided until the owner decides the policy.

Cells that start with `=`, `+`, `-` or `@` are prefixed so a spreadsheet does not treat them as formulas.
