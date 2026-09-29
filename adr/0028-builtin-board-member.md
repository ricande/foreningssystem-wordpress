# ADR-0028: Built-in board member role

**Status:** Accepted

**Date:** 2026-09-29

## Context

The board step lists the seeded offices: chair, treasurer, secretary, alternate, auditor, and election committee. An ordinary board member is not among them. Several people hold that office, and the name is not something the association renames.

The minutes step already offers Board member (`assoc_board_member`) as one of the four association roles. Adding a custom board role with the same name listed that role a second time.

A duplicate of an existing role or meeting type was already refused by the structure services. The wizard reported that refusal as a generic save failure.

## Decision

- `board_member` is a built-in board role. Several people may hold it. Its name and holder rule cannot be changed, and it cannot be removed.
- Schema 17 inserts that role when the slug is missing. Fresh installs also receive it from the original board-role seed. No table changes. Plugin version stays 0.1.0.
- The minutes step keeps one Board member choice, the association role. The board seat is not added beside it. A custom role whose name repeats Secretary, Chair, Treasurer, or Board member, in English or Swedish, is not listed there either.
- A new board role or meeting type is refused when its name matches an existing row or a built-in English or Swedish label. The wizard says that the name already exists.

## Consequences

- An install that already added a custom role named Styrelseledamot or Board member still has that row. The minutes step does not show it next to the association role. The custom row can be removed while nothing uses it.
- Setup version stays 1. ADR-0023 kept setup state independent of schema; that still holds.
