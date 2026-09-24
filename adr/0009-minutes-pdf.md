# ADR-0009: Minutes PDF is a plain-text render of the stored revision

**Status:** Accepted  
**Date:** 2026-09-24

## Context

ADR-0006 locks the contract: render on the server from the stored revision, keep a hash of that source, and reuse the file when the hash still matches. It leaves the library open until a spike. The stored minutes body is already plain text. The plugin has no runtime Composer autoload.

## Options considered

### Option A — Dompdf

Pros:
- HTML and CSS. DejaVu covers Swedish letters.

Cons:
- LGPL library, large, and memory-hungry on shared hosting.
- First runtime dependency, and the minutes are not HTML yet.

### Option B — mPDF

Pros:
- GPL-2.0, strong Unicode, maintained.

Cons:
- Heavier than the document we store. Same runtime-dependency cost as Dompdf.

### Option C — A small PDF writer in the plugin, using the standard Helvetica font and WinAnsi

Pros:
- No runtime dependency and little memory.
- WinAnsi includes å, ä and ö.
- The input is the revision body, so a later edit to live meeting rows cannot change the file.
- Pagination, including keeping a heading with the following line, can be tested without WordPress.

Cons:
- No CSS layout. A future HTML minutes template would need a different renderer.
- Characters outside WinAnsi are omitted.

## Decision

Use option C for the minutes PDF. The ADR-0006 contract still holds. The source hash covers the revision body and payload, so a hand edit of the draft produces a new file, while an unchanged locked revision reuses the file.

The file is stored under `uploads/assoc-private`, with a deny rule for direct web access. Download and print go through a capability check. A finalized revision requires `view_internal_meetings`. A draft or a revision under adjustment requires `record_meeting`. The screen does not publish the file URL. This follows ADR-0005: an attachment URL is not the access check.

## Consequences

Positive:
- Swedish minutes text can be exported on ordinary hosting.
- The locked revision stays the source of the PDF.

Negative/tradeoffs:
- Hosts that ignore the deny rule can still read a known file path. The screen says the download is the intended door.
- A designed HTML template later replaces the renderer, not the hash or the download check.

## Revisit triggers

The minutes body becomes HTML, or WinAnsi cannot represent the association's language.
