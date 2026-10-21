# ADR-0030: The structured member file is the backup; the plain file says what it leaves out

**Status:** Accepted  
**Date:** 2026-10-21

## Context

ADR-0011 and ADR-0020 describe the plugin's two member files. A review in the lab found four ways a round trip lost data without saying so:

- The structured reader split lines byte by byte. Byte `0x85` inside UTF-8 `Å` was read as a line break, so `Åsa` became two broken records.
- The structured writer joined cells with `;` and did not quote them. A `;` in a name moved the last name into the email column.
- The structured file did not carry person status. A deceased person came back as known.
- The plain file has one row per period and participant, but import skipped every row whose membership number already existed. A returning member's later period and every family member after the first were dropped and only counted as skipped.

The plain file has no participation interval and no participant role. It cannot rebuild a family or a returning member without inventing join and leave dates.

## Decision

The structured file (`# foreningsplugin-members 2`) is the file an officer uses to back up and restore the member register.

- It is read and written with PHP's CSV functions. A cell that holds `;`, `"`, a space, or a line break is quoted. A quote inside a cell is doubled. A backslash is plain text (RFC 4180). A quoted cell may span lines, and an error reports the line where the record starts.
- Both files use RFC 4180 quoting. Older plain files read the same way unless a cell contains a backslash right before a quote.
- Participant rows gain an eleventh column, `person_status` (`known` or `deceased`), after `ended_on`. A row without the column is a known person, so older files still import. An unknown value is an error on that line.
- A deceased person is created as known while the rows are read, and is marked deceased after every participant row is in, so the ended history passes the usual admission rules. A deceased person in the file with an open member participation is rejected. Status in a file never changes a person who is already stored.
- A participant without email is found again only on the same membership, by first name, last name, and birth date, so a repeated import does not create a second child. The same name on another membership is still a different person.
- A cell that starts with `=`, `+`, `-`, `@`, a tab, or a carriage return, optionally after apostrophes, gets one more leading apostrophe on export. Import removes one. A value that really starts with an apostrophe survives the round trip.

The plain file keeps its meaning: one period and one member per membership. It does not attach later rows to an existing membership.

- A row whose membership number was created from an earlier line in the same file is not imported. It is reported on its own line, names the membership number, and points the officer to the structured member file.
- A row whose membership number existed before the import is still skipped, so importing the same file again changes nothing.
- A plain row creates a participation that starts with the period and has no end, as before.

A row that fails to save for an unexpected reason is reported as `The row could not be saved. No part of it was kept.` on its line. The row's transaction is rolled back, and the other rows continue. A failure around the whole file shows a notice on the member screen instead of a fatal error.

## Consequences

Positive:

- Export then import into an empty register gives the same people, periods, participants, organizations, and person statuses from the structured file. Tests cover a returning member, a family with two members, a deceased person, names with `Å`, `;`, `"` and a trailing backslash, and formula-like names.
- The plain file no longer reports lost rows as unchanged.

Negative:

- The plain file still cannot rebuild families, returning members, or participation end dates. The officer is told which rows were left out.
- Personal identity numbers stay out of both files (ADR-0020).
- Email is still not an identity key outside one membership. A person without email on two memberships becomes two people on import, as in ADR-0011.
- A new structured file with a quoted cell that spans lines does not read in a plugin version older than this change.
