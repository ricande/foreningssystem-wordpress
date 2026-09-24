# Agent instructions

## Mission

Build a high-quality open source WordPress association-management plugin for small and medium-sized nonprofit associations.

The plugin must become the association's administrative backbone while remaining recognizably WordPress.

## Non-negotiable product principles

1. Self-hosted first. No mandatory SaaS.
2. WordPress remains WordPress. Do not build a theme or replace normal WordPress content management.
3. A member is not the same thing as `wp_user`.
4. Structured association data should have one source of truth.
5. Historical data is a first-class requirement.
6. Privacy/GDPR must influence architecture from the beginning.
7. Use least privilege and WordPress capabilities.
8. Prefer integration over rebuilding unrelated systems.
9. No trackers, advertisements, hidden callbacks, telemetry, or secret external dependencies.
10. The free/open-source base product must be genuinely useful.
11. Do not silently overwrite finalized historical records such as adjusted meeting minutes.
12. Do not choose a persistence mechanism just because it is fastest to code.

## Work discipline

Before implementation:
- read all project docs
- identify ambiguities
- propose explicit decisions
- record important decisions as ADRs
- separate facts, assumptions, proposals and locked decisions

Do not silently change a locked product principle.

When there are multiple reasonable technical approaches:
- compare them
- describe tradeoffs
- recommend one
- record the decision before building around it

## Implementation discipline

Once implementation is approved:
- use modern PHP compatible with the agreed WordPress/PHP baseline
- follow WordPress Coding Standards
- use namespaces
- separate domain logic from WordPress integration
- use prepared SQL for custom queries
- validate input
- sanitize where appropriate
- escape output as late as practical
- perform capability checks
- use nonce/CSRF protection for state-changing browser actions
- secure REST endpoints with explicit permission callbacks
- never trust client-side authorization
- avoid direct file access
- avoid global mutable state when possible
- make destructive operations explicit and testable
- make critical workflows idempotent where appropriate

## Testing

Every critical operation must be testable.

Tests must cover at least:
- permissions
- member lifecycle
- board history
- meeting workflow/state transitions
- decision creation
- minutes revisions
- finalized minutes immutability
- PDF/export metadata contract
- signed-copy association
- privacy export/erase rules
- migrations
- REST authorization if REST is used
- rendering/escaping of public blocks

Do not mark a feature complete because a happy-path browser click worked.

## Git discipline

- Keep commits focused.
- Do not include generated dependencies, secrets, VM credentials, database dumps with personal data, or local configuration.
- Never add `Co-authored-by` or AI attribution trailers unless the repository owner explicitly requests them.
- Do not create releases or tags unless explicitly requested.
- Keep the working tree understandable and report unexpected pre-existing changes before touching them.
