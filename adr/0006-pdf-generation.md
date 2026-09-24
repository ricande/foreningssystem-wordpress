# ADR-0006: PDF is a server-side render of a frozen revision

**Status:** Proposed  
**Date:** 2026-09-24

## Context

Print and PDF are locked core features. Electronic signature services are not. The file must be produced on ordinary WordPress hosting without a PDF SaaS. The library is still an open choice.

## Options considered

### Option A — Browser print only

Pros:
- No library, no memory risk, Swedish text just works.

Cons:
- Does not meet the locked PDF requirement.
- Output depends on the officer's browser.

### Option B — Bundle a PHP PDF library and render the finalized snapshot on the server

Pros:
- Works without an external account.
- Input is the immutable revision, so the PDF has a defined source.
- The print view remains available if the library fails.

Cons:
- Libraries differ in CSS, fonts, memory, and maintenance.
- Byte-identical output across library upgrades is unrealistic.

### Option C — An external PDF API

Pros:
- Better layout engines exist as services.

Cons:
- Mandatory external service. Rejected by the product principles.

## Decision

Propose option B for the architecture, and do not pick the library until a spike on the lab compares maintained candidates such as Dompdf and mPDF.

Contract to lock only after the spike, but recommended now:

- Generate from the finalized revision payload, not from live meeting rows.
- Store a hash of that canonical payload with the attachment.
- If the hash still matches, reuse the existing PDF instead of pretending a new render is a new revision.
- Require content equivalence, not byte-identical files, when the library or fonts change.
- Embed a font that covers Swedish characters.
- Keep the print stylesheet even when PDF works.

## Consequences

Positive:
- Hosting stays self-hosted.
- A library change does not rewrite minutes history.

Negative/tradeoffs:
- The spike may reject both named libraries. The print view is the fallback while that happens.
- Shared-host memory limits may force a simpler layout.

## Revisit triggers

The spike shows no maintained library can render a multi-page Swedish minutes document under a modest memory cap. That would require an explicit product change, not a quiet switch to a SaaS.
