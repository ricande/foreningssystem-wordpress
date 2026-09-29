# Sections and departments

**Status:** FUTURE / NOT IMPLEMENTED / PROPOSAL.

**Not locked.** This document does not authorize code, a migration, or a schema change. Plugin version stays 0.1.0. Schema stays 16.

**Context:** product idea recorded 2026-09-29. Nothing in the plugin represents a section today.

Do not read this as a feature that exists. There is no section table, no section screen, no section capability, and no section document type.

The English word "section" in this file is a working label for the idea. The Swedish product word — sektion, avdelning, or a label the association chooses — is an open question (Q46). This filename does not settle it.

## What the idea is

Many associations are organized into parts such as football, skiing, youth, veterans, culture, a competition group, or a recreational group.

A future version should be able to represent those parts. A section belongs to the association. It is not, by itself, a separate legal association. One WordPress installation still represents one association, as the current model assumes.

An association may have no sections, one section, or many. An association with no sections must remain a valid association. Sections are optional structure, not a requirement for membership, the board, or meetings.

## Principles this must fit

These are already stated in `AGENTS.md`, `DECISIONS.md`, and the current domain docs. This proposal does not reopen them.

| Principle | What it means here |
|---|---|
| A Person is not a Membership and not a `wp_user` | Section membership, if it exists, is another relation. It does not replace association membership and it does not create a WordPress account. |
| Least privilege | A person who looks after one section does not thereby administer the association. |
| History is kept | Closing a section, or changing who is responsible, does not erase past years. |
| No bookkeeping in the core product | Section money, if built, is internal tracking. It is not the association's legal accounts. See the MVP non-goal in `DECISIONS.md`. |
| One source of truth | An activity report and its meeting use should reuse Documents and Meetings. They should not become a second archive. |
| Privacy from the start | A section officer does not receive the whole Person register because that is easier to build. |

## SECTIONS-1 — Organization

**Not implemented.**

A section should at least be able to carry a name, a description, a status such as active or inactive, a responsible person, and a year of activity where that year matters. Exact fields are not a schema.

Every active section has at least one responsible person. That person does not have to be a board member. They may be an ordinary member of the association. They should be able to receive a limited permission for their own section.

That permission does not automatically include `manage_association`, the whole member register, board capabilities, or any other section.

A member should, as product direction, be able to belong to no section, one section, or several sections at the same time. Belonging to a section is separate from the person's membership in the association. A person can be a member of the association and belong to no section.

The exact model for section membership, its history, and any section roles besides the responsible person needs a detailed design before implementation. See Q47–Q50.

## SECTIONS-2 — Activity reports

**Not implemented.**

Each section should be able to write and hand in an activity report for a year of activity. An example title is "Fotbollssektionen – Verksamhetsberättelse 2027".

A smallest workflow to discuss is draft, then submitted, then accepted or archived. Those names are not locked. `docs/05_MEETING_WORKFLOW.md` already treats meeting state names as unset, and documents already have their own visibility. A later design should use that vocabulary where it fits, instead of inventing a parallel document life cycle.

The section's responsible person should be able to write or assemble the report. The report may include a summary of the year, activities that took place, results or events, member or participant figures where they are relevant, plans, and an economic summary.

The report should join the existing Documents, Meetings, and archive model. It should not be a separate document universe. How that join works is Q51.

## SECTIONS-3 — Meeting material

**Not implemented.**

A submitted activity report should be usable as material for a meeting. The important case is the annual meeting.

A future screen might list each section's report for that meeting and show which reports have been handed in and which are still missing. The board, or whoever administers the meeting, should be able to see that difference. An example shape, not a specification:

```text
Material for the annual meeting
[x] Activity report — football section
[x] Activity report — youth section
[ ] Activity report — ski section
    Not submitted
```

A report should be attachable to a meeting, usable as meeting material, able to follow the relevant meeting and archive flow, and kept with its history. The implementation of that link should wait until Meetings and Documents are analyzed for it. Do not treat the sketch above as a screen that exists.

## SECTIONS-4 — Budget

**Not implemented.**

Each section should be able to have its own internal budget for a year of activity.

This is not a bookkeeping system. The first idea is a simple record of a budget, budget lines, income, expenses, and follow-up. An illustration, not a defined formula:

```text
Football section 2027
Budget:              40 000
Recorded income:     12 400
Recorded expenses:   31 250
Budget remaining:     8 750
```

"Budget remaining", net, income, and expenses must not be mixed up. The calculation has to be defined before any implementation. The numbers above do not decide it.

A budget should be splittable into lines or categories, for example materials, travel, events, training, and other. The category list is an example, not a fixed chart of accounts.

How a section's year relates to the association's membership year is Q53.

## SECTIONS-5 — Simple economic tracking

**Not implemented.**

A section's responsible person, or another future section role if one is decided, should be able to record simple economic events. The smallest data to discuss is a date, a description, a type of income or expense, an amount, a budget line or category, and an optional comment or reference.

This is internal economic follow-up. It is not the association's legal bookkeeping. The product must not grow an incomplete ledger and describe it as accounting.

Export to a real bookkeeping system, and whether an event needs a receipt attachment, are separate product questions (Q54 and Q55).

## SECTIONS-6 — Year summary

**Not implemented.**

An activity report should be able to include a generated economic summary for that year: budget, income, expenses, and outcome per budget line. That summary can then travel with the report as material for a meeting, such as the annual meeting.

Later, the association should be able to see one year across sections: which activity reports have been handed in, and which economic summaries are ready. An illustration:

```text
Year 2027
Football — report handed in, economy summarized
Youth — report handed in, economy summarized
Skiing — report missing, economy not ready
```

This overview is future work. It is not a screen in the plugin.

## Permission

**Not implemented. No capability name is chosen here.**

Sections add a permission problem the current model does not express. Today's association capabilities are association-wide. `RoleBundles` is the four roles `assoc_secretary`, `assoc_chair`, `assoc_treasurer`, and `assoc_board_member`. Board structure roles are seats, not those capability bundles. A custom board role can be granted minutes permissions. None of that means "this person may manage only this section".

A future implementation needs to say, in effect, that person X may handle section Y, without that meaning person X may handle the whole association. That covers at least section information, activity reports, the section budget, and the section's economic events.

The responsible person still must not automatically receive `manage_association`, the whole member register, board authority, or other sections.

How that fits capabilities, Person, and `wp_user` needs an architecture pass before a solution is chosen. This document does not add a capability and does not store a grant. See Q57 and `docs/CAPABILITY_MATRIX.md`.

## History

History matters in the same way as board assignments and finalized minutes.

If the responsible person changes, earlier years still show the historical information for those years. If a section is closed, its activity reports, meeting material, budgets, and economic summaries remain.

The bias is archive-first. Hard delete is not the assumed model for a section that already has history. The current privacy model already refuses a casual delete of a Person, a Membership, a board assignment, or a finalized revision. A section with history should be thought of the same way until a later decision says otherwise.

## Privacy

**Not implemented. No export or erasure rule for sections exists, because sections do not exist.**

Before implementation, these have to be analyzed. This list does not answer them.

- Which personal data a section's responsible person may see.
- How section membership, if stored, appears in a privacy export.
- How erasure or anonymization treats historical section data.
- Whether an economic event can contain personal data.
- What retention applies to section documents and economic events (Q56).

Giving the responsible person the whole Person register, so the feature is easier to build, is not an acceptable shortcut.

Current export, erasure, and retention are described in `docs/PRIVACY_MODEL.md`. They do not mention sections.

## Likely first implementation boundary

Still not scheduled, and not a commitment to build. If a first version is built later, it should probably stay inside SECTIONS-1 through SECTIONS-6: organization, activity reports, meeting material, budget, simple economic tracking, and the year summary.

Explicitly out of scope for that first version:

- full double-entry bookkeeping
- a general ledger
- VAT returns
- bank integration
- payments
- invoicing
- tax filings
- a complete accounting system
- several legal associations in one installation

Fees, activities, and mailings remain their own deferred areas. This proposal does not absorb them.

## Open questions

Nothing in this file locks an answer. The questions are Q46–Q57 in `OPEN_QUESTIONS.md`.
