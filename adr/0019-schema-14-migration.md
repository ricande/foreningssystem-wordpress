# ADR-0019: Migration from schema 14

**Status:** Accepted  
**Date:** 2026-09-24

## Context

Schema 14 stores `person_id`, `membership_number`, `membership_type`, `status`, `started_on` and `ended_on` on `assoc_membership`. The number is unique per row.

## Decision

Schema 15 renames that table to `assoc_membership_legacy` and creates the new tables. Each legacy row becomes:

1. one membership with the same number
2. one participant, role `member`, primary, for the same person
3. one period with the same status and dates, and the old type string as the historical class

Known type words map to `ordinary`, `youth` or `family`. A legacy word that looks like a company still becomes `ordinary`, because there is no organization to attach. The original word stays on the period.

Rows are not merged. Each legacy row is copied inside its own database transaction. If the membership already exists, the copy checks the participant and the period and inserts only what is missing. A rerun after a partial failure therefore completes the aggregate instead of skipping it. The schema version advances only after `up()` returns.

`assoc_membership_legacy` stays after the copy. Runtime code reads `assoc_membership`, `assoc_membership_period` and `assoc_membership_participant`. It does not read the legacy table except this repair. The table is a safety copy for backups and for completing a partial upgrade. A later cleanup migration may drop it after the owner decides the copy is no longer needed. This batch does not drop it.

The copy routine in schema 15 was corrected in place. The `CREATE TABLE` statements were not rewritten. Schema 16 runs the same repair again so a database that already reached version 15 with a hole can still be completed. The reason is the finding that a partial schema 15 copy could not be repaired while the version was still 14, and a completed-but-incomplete version 15 would never re-enter schema 15.
