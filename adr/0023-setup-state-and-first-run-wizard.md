# ADR-0023: First-run setup state and wizard

**Status:** Accepted  
**Date:** 2026-09-25

## Context

Fresh installs need a first-run setup guide for association structure. Existing installs already configured before the wizard must not be forced through it. Setup progress must not be confused with the database schema version.

## Decision

- Local WordPress options carry setup state: `assoc_setup_version`, `assoc_setup_step`, and `assoc_setup_redirect_pending`.
- `CURRENT_SETUP_VERSION = 1`. Value `0` or an absent option means incomplete. Value `1` means Wizard v1 is complete.
- Setup version is independent of `assoc_schema_version`. Schema stays at 16 for this work. No schema 17.
- On activation, read schema **before** migration:
  - Schema absent/`0` and setup option absent → migrate schema, write setup `0`, set first-run redirect pending (fresh install).
  - Schema `> 0` and setup option absent → adopt setup `1` without redirect (pre-wizard existing install).
  - Incomplete setup on later activation may recreate redirect pending. Completed setup does not reopen or redirect.
  - Failed migration does not set redirect pending.
- Capability gate is `manage_association` only. Unauthorized users do not consume the pending redirect.
- While incomplete, Association navigation is limited to Get started. Capabilities and application services remain; direct URLs keep their page caps. This is onboarding restriction, not a new auth model.
- The wizard reuses canonical services: association profile, board role definitions, meeting type definitions, minutes lock/publish, retention, and private-storage warning status. No parallel settings store.
- Reopening the guide from Settings after completion does not mark setup incomplete or reset domain data. Finish keeps setup version `1`.

## Consequences

- Fresh ZIP installs stay incomplete until an officer finishes the wizard (or an explicit test completes it).
- Existing development labs that already have schema `> 0` adopt setup `1` on activation or via `adoptPreWizardIfNeeded()` when the setup option was never written.
- Help & Guides and registered-install bulletins remain out of scope; this ADR covers setup state and first-run wizard semantics only.
