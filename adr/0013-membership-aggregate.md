# ADR-0013: Membership is separate from its periods

**Status:** Accepted  
**Date:** 2026-09-24

## Context

The owner decided that a membership number belongs to the membership. A returning member opens another period on that membership. The number is not reused for a different membership.

## Decision

`Person`, `Membership` and `MembershipPeriod` are separate. `assoc_membership.membership_number` is unique. Periods reference the membership and do not carry the number.

Built-in kinds for this version are the slugs `ordinary`, `youth`, `family` and `company`. Labels are translated in WordPress. An unknown stored slug is invalid and will not load. Adding another kind later needs a domain change. Custom kinds remain an open owner question and are not implemented.

`Membership.kind` is the current classification. `MembershipPeriod.historical_class` is the classification recorded for that period. A date uses the period's historical class. A new period copies the current kind into its historical class. The two may differ when an older free-text class was mapped to `ordinary`, or when an earlier period keeps `youth` and the current kind is later `ordinary`. There is no automatic change from youth to ordinary when someone turns 18.

## Consequences

Schema 14 rows are copied one-to-one in schema 15. Two old numbers for the same person stay two memberships, because the old data cannot prove they were one membership.
