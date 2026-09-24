# ADR-0008: Minimum PHP 8.2 and WordPress 6.7

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The technical baseline is listed as open in `DECISIONS.md`. The lab runs PHP 8.3 and WordPress 7.1.2. The published plugin needs an older floor so ordinary associations are not forced onto the newest release on day one.

## Options considered

### Option A — Support the oldest PHP and WordPress still seen on shared hosting

Pros:
- Maximum install base.

Cons:
- Gives up typed domain code and current WordPress APIs.
- Testing matrix becomes the project.

### Option B — PHP 8.2 and WordPress 6.7

Pros:
- PHP 8.2 is the oldest 8.x line that is a reasonable target in 2026 without writing 7.4 compatibility.
- WordPress 6.7 is recent enough for current block APIs and old enough that sites which update at all can run it.
- The lab is newer, so it stays the development target. A minimum combination is a release check, not the daily environment.

Cons:
- Sites on PHP 8.1 or older WordPress cannot install it. That should be an honest plugin header, not a surprise.

### Option C — Match the lab exactly: PHP 8.3 and WordPress 7.1

Pros:
- One environment to test.

Cons:
- Narrower than necessary for a WordPress.org plugin. WordPress 7.1 is current lab fact, not a compatibility promise.

## Decision

Option B stays a proposal. It is not approved, and the project does not test PHP 8.2 or WordPress 6.7.

Until the owner locks a floor, the plugin header, Composer, and the test run name only the runtime they actually use: PHP 8.3 and WordPress 7.1 in the lab. That header is a tested-runtime marker, not a decision that older WordPress is unsupported forever. A compatibility job for any lower pair waits until the owner approves the floor. Do not claim support that the test matrix does not cover.

## Consequences

Positive:
- Domain code can use modern PHP.
- The release checklist has two environments: lab current, and the minimum pair.

Negative/tradeoffs:
- The minimum pair is not installed in the lab yet. Phase I needs that check before a public release.

## Revisit triggers

WordPress.org or the owner sets a different floor before the first release. Or PHP 8.2 security support status makes 8.3 the practical minimum.
