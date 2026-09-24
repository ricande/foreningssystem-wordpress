# ADR-0018: Protected identity capabilities

**Status:** Accepted  
**Date:** 2026-09-24

## Context

Editing ordinary member data is not the same as reading a personal identity number.

## Decision

`view_personal_identity_numbers` is required to read the full number or the masked form `2012••••-1234`. `edit_personal_identity_numbers` is required to save or remove it. Masking is not a substitute for the view capability.

Neither capability is in the secretary, chair, treasurer or board-member bundles. The WordPress administrator receives both because that role receives every plugin capability.

The member list shows nothing about the number to a user without the view capability. Audit events store the object id, action, actor and time. They do not store the number.

Search by the full number is not implemented.
