# ADR-0015: Company membership

**Status:** Accepted  
**Date:** 2026-09-24

## Context

A company membership is not a person membership.

## Decision

An organization has a name, an optional organization number and contact fields. The membership kind is `company` and points at that organization. Linked people use the participant role `contact` unless a later decision says otherwise. A contact is not an individual member and cannot hold a board assignment on that membership alone.

Organization numbers use their own 10-digit checksum, and the third digit must be 2–9 so the value is not shaped like a personal identity number. This is format and checksum validation only. It is not a company-register lookup. They are not personal identity numbers. Several organizational units inside one company are not modeled yet.
