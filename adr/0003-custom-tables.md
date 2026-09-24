# ADR-0003: Structured association data lives in custom tables

**Status:** Proposed  
**Date:** 2026-09-24

## Context

`DECISIONS.md` leaves persistence open. `docs/DATA_STORAGE_PLAN.md` compares each object. The choice constrains migrations, privacy, and the minutes snapshot.

## Options considered

### Option A — Custom post types for people, meetings, and minutes

Pros:
- List tables, the block editor, and post revisions already exist.
- Public permalinks come for free.

Cons:
- Membership periods, board date ranges, and decision follow-up become post meta.
- WordPress revisions are not an immutable correction chain.
- Private association data is easy to expose through public query assumptions.
- Post meta is a poor index for "treasurer on date D".

### Option B — Custom tables for association records, WordPress content for the public site

Pros:
- Relations, uniqueness, and history match the domain.
- Finalized minutes can be frozen without fighting post updates.
- Pages and dynamic blocks still belong to WordPress.

Cons:
- The plugin owns its schema, migrations, and admin lists.
- The meeting editor will not be the block editor unless we later embed it on purpose.

### Option C — Mixed: meetings as posts, people as tables

Pros:
- Minutes could reuse the block editor.

Cons:
- Two persistence styles for one workflow.
- A public meeting post and a private minutes record are easy to confuse.
- Snapshot immutability is still custom.

## Decision

Propose option B, as detailed in `docs/DATA_STORAGE_PLAN.md`. Files use the Media Library. The public site uses pages and dynamic blocks. Do not store people or meetings as posts.

## Consequences

Positive:
- Privacy, history, and minutes rules have a place to live.
- WordPress remains responsible for the site around the plugin.

Negative/tradeoffs:
- Phase B must include a migration runner before feature tables.
- Admin UX is our responsibility instead of `wp-admin/edit.php`.

## Revisit triggers

A later public "meeting page" needs a permalink and comments. That can be a normal WordPress page that a block fills, without moving the system of record into posts.
