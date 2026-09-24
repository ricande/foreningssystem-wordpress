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

Rows are not merged. Copying skips a number that is already present, so a failed migration can be run again without duplicating memberships. The schema version advances only after `up()` returns. The legacy table is kept.
