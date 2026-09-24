# ADR-0021: Encryption of personal identity numbers

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The number must be readable by an authorized user, so hashing is not a solution. A homemade cipher would be worse than plaintext behind access control. WordPress salts are a poor key: they are shared with other features, they rotate when a site is repaired, and a backup often contains both the database and the salts.

## Decision

The number is not encrypted. It is stored in its own table, behind the capabilities in ADR-0018, and it is omitted from public output, ordinary CSV and audit text. The remediation batch did not add encryption.

Encryption at rest stays an owner decision. A later design needs a plugin-specific key outside the database backup, a documented restore and rotation procedure, and a defined outcome when the key is lost. Multisite would need one key per site or an explicit shared-key decision. `AUTH_KEY` is not that key.
