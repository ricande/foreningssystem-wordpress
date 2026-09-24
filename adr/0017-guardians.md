# ADR-0017: Guardians and approval records

**Status:** Accepted  
**Date:** 2026-09-24

## Context

The association may need to record guardians and an approval. The plugin must not claim that every person under 18 requires guardian consent.

## Decision

A guardian is a `Person`, with or without a membership. The relationship is an explicit row: child, guardian, label and optional dates. A child may have more than one guardian. The same child and guardian may have more than one row when the intervals do not overlap. An open or overlapping interval for that pair is rejected. A missing start means the relationship is treated as valid from the beginning until its end.

An approval is accepted only when a relationship between those people covers the approval date. A withdrawal cannot be dated before the approval.

An approval records the child, the guardian, purpose, basis note, time, method, notice version, who recorded it, and a withdrawal time when it is withdrawn. Withdrawing it keeps the row. Ending a family membership does not delete the relationship.

The record states what the association says occurred. It is not a legal determination.
