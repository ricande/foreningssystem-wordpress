# ADR-0005: Private files are not private because they are attachments

**Status:** Proposed  
**Date:** 2026-09-24

## Context

The Media Library is the locked place to store files. On ordinary hosting, files under `uploads` are often reachable if the URL is known. Derivatives, previews, and caches can leak them too. Member and board documents, signed minutes, and generated PDFs of unpublished minutes are sensitive.

## Options considered

### Option A — Store everything in `uploads` and rely on unlisted URLs

Pros:
- Works everywhere WordPress works. No extra server configuration.

Cons:
- Unguessable URLs are not authorization.
- Anyone with the URL, a backup, or a search index can read the file.

### Option B — Store protected files outside the web root and stream them after a capability or membership check

Pros:
- The URL of a PHP handler is the only door, and it checks access.
- Matches least privilege.

Cons:
- Some shared hosts only allow writes under `uploads` or `wp-content`.
- The plugin must range-stream downloads itself and disable public derivatives for those attachments.

### Option C — A private-media plugin or SaaS as a requirement

Pros:
- Someone else solves storage.

Cons:
- Breaks self-hosted first and adds a mandatory dependency.

## Decision

Propose option B where the host allows a directory outside the public web root. When it does not, fall back to a dedicated uploads subdirectory plus the same authenticated handler, and show the administrator a clear warning that direct file access may still be possible. Never treat "unlisted" as the security control.

Recommended signed-copy types: PDF, JPEG, PNG.

The signed scan is linked to one finalized revision. The owner locked on 2026-09-24 that the signed copy is the archival original when it differs from the finalized text. The revision remains immutable and is still kept. It does not outrank the signed copy.

## Consequences

Positive:
- Public blocks cannot leak a board PDF by printing its attachment URL.
- The same rule covers REST and the browser.

Negative/tradeoffs:
- A hosting limitation remains on the fallback path. The lab and the documentation must say so.
- Image sizes and offloaded storage plugins can fight this. That needs a test when implementation starts.

## Revisit triggers

WordPress core gains a supported private attachment API that actually blocks direct web-server reads. Or the owner accepts that MVP documents are public-only, which would drop member and board visibility from the first release.
