# ADR-0013: Membership is separate from its periods

**Status:** Accepted  
**Date:** 2026-09-24

## Context

The owner decided that a membership number belongs to the membership. A returning member opens another period on that membership. The number is not reused for a different membership.

## Decision

`Person`, `Membership` and `MembershipPeriod` are separate. `assoc_membership.membership_number` is unique. Periods reference the membership and do not carry the number.

Built-in kinds are the slugs `ordinary`, `youth`, `family` and `company`. Labels are translated in WordPress. Unknown future slugs can be stored without a schema change. An admin screen for custom kinds is not part of this batch.

## Consequences

Schema 14 rows are copied one-to-one in schema 15. Two old numbers for the same person stay two memberships, because the old data cannot prove they were one membership.
