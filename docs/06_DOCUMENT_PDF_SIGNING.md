# Minutes, print, PDF and signed hard copy

## Principle

The system must support associations that still require or prefer paper signatures.

Digital convenience must not remove the ability to create a stable hard copy.

## Document layers

A meeting can have four related but distinct representations:

1. **Structured meeting data**
2. **Minutes revision**
3. **Generated PDF**
4. **Signed hard copy scan/upload**

Do not collapse these into one ambiguous blob.

## Finalized minutes revision

When a revision becomes adjusted/finalized:
- content must no longer be silently mutable
- it receives a stable revision identity
- metadata records when/by whom it was finalized as appropriate
- later corrections create a new explicit revision/correction path

Example identity:
`Board meeting 2026-09-24 — Minutes — Revision 1`

## Print view

The finalized minutes should have a clean print representation:
- A4-friendly
- no WordPress admin chrome
- stable page breaks
- page numbering
- association name
- optional logo
- meeting type/date/place
- participant section
- numbered sections
- decisions
- signature area

## PDF

Core requirement:
- export finalized minutes as PDF
- generated server-side
- no mandatory external PDF SaaS
- predictable rendering
- compatible with realistic shared WordPress hosting constraints

Technical library choice is OPEN and must be captured in an ADR.

Evaluate:
- maintenance status
- licensing
- PHP/WordPress compatibility
- CSS support
- security history
- bundle size
- font handling
- deterministic behavior
- accessibility/metadata limits
- resource consumption on modest hosting

## Signature section

Template-driven signature roles may include:
- meeting chair
- secretary
- one or more adjusters

Do not hardcode one legal convention for every association type/country.

## Signed hard copy

Workflow:
1. finalize minutes
2. generate PDF
3. print
4. sign manually
5. scan
6. upload signed copy
7. link it to the exact finalized MinutesRevision

The uploaded signed copy should have metadata identifying its role.

## Private file warning

WordPress Media Library attachment URLs can be publicly reachable depending on storage/server configuration.

Therefore:
- do not assume “attachment is private” because plugin metadata says so
- design protected-document delivery before member/board-only documents are considered secure
- direct URLs, previews, derivative images and caches must be considered
