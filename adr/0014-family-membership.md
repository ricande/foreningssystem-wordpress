# ADR-0014: Family membership

**Status:** Accepted  
**Date:** 2026-09-24

## Context

A family membership is one membership with several people, not one person with a type label.

## Decision

Participants are separate `Person` rows. Roles are `member` and `contact`. `is_primary` marks the primary contact. Only `member` counts as an individual member and can cover a board assignment. Age comes from an explicit birth date, not from a role named child.

A guardian relationship is not inferred from the family membership.

Moving someone to an ordinary membership reuses the person when the administrator chooses that person. It does not create a second person by itself.
