# ADR-0022: Participation intervals

**Status:** Accepted  
**Date:** 2026-09-24

## Context

A participant row had an end date and no start date, so a person who joined a family membership later was treated as present from the membership's first period. The unique key on membership and person also blocked a later join after the person had left.

## Decision

Schema 16 adds `started_on` and drops the unique key. Coverage, counts, board eligibility and retention use the overlap of the period and the participation. Both ends are inclusive.

Existing rows take the earliest period start on that membership. Schema 14 had one person per membership, so that date is the participation start. Participants created while schema 15 was current have no recorded join date, and the same earliest period start is the deterministic fallback. It can include time before a real later join. It does not invent a later date the system never stored.

A person who leaves and rejoins gets a new participation row. The ended row stays.

## Consequences

See ADR-0019 for how a partial schema 15 copy is repaired before these dates are filled in.
