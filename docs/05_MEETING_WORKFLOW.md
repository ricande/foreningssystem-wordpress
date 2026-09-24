# Meeting workflow

Meeting administration is a core product feature.

## Goal

Reduce the common small-association workload where the secretary:
- creates an agenda
- takes unstructured notes
- reconstructs the meeting afterward
- identifies decisions manually
- rewrites everything into minutes
- exports/prints it
- gets signatures
- archives it
- separately updates the website

The plugin should connect these steps.

## Proposed lifecycle

The exact state names are not yet locked.

```text
PLANNED
  ↓
IN_PROGRESS
  ↓
COMPLETED
  ↓
MINUTES_DRAFT
  ↓
UNDER_REVIEW / FOR_ADJUSTMENT
  ↓
FINALIZED / ADJUSTED
  ↓
ARCHIVED and/or PUBLISHED
```

Cursor must design:
- valid transitions
- capabilities per transition
- what data remains editable in each state
- correction/revision rules

## Before the meeting

Create a meeting from:
- blank meeting
- meeting template
- previous meeting structure, if explicitly supported later

Configure:
- type
- date/time
- place
- participants/invitees
- agenda
- attachments
- expected chair/secretary/adjusters

## During the meeting

Each agenda item is a working surface.

Possible actions:
- write notes
- add/edit decision
- add action item
- add attachment/reference
- mark agenda item handled/postponed
- add late agenda item if rules permit

The UI should optimize fast secretary work, not force navigation between unrelated admin pages.

## Decision capture

Example:

Agenda item: Equipment purchase

Notes:
“Three offers were reviewed.”

Decision:
“The association purchases model X from Company AB for no more than SEK 12,000.”

Responsible:
Treasurer

Deadline:
2026-10-30

A Decision is structured and can later appear:
- in minutes
- in an open-decisions view
- on the dashboard
- in follow-up workflows

## Action item capture

Not every task is a formal decision.

Example:
“Anna contacts the municipality about the lease.”

ActionItem may include:
- assignee
- due date
- status
- source agenda item
- source meeting

## Minutes draft

The first version should support deterministic composition from:
- meeting header data
- attendance
- agenda headings
- selected notes
- decisions
- action items where configured
- closing information

No AI is required for MVP.

If AI is ever added later, it must be optional and must not become a mandatory external dependency.

## Annual meeting special case

Annual meetings should use configurable templates based on the association's bylaws.

Typical headings might include:
- voting list
- meeting properly convened
- agenda approval
- election of meeting chair/secretary/adjusters
- annual report
- financial report
- auditor report
- discharge from liability
- fees
- budget/business plan
- board elections
- auditor election
- election committee
- other business
- closing

These are examples, not hardcoded Swedish legal rules.

## Elections and downstream updates

An adjusted annual-meeting decision may later offer an explicit action:

“Update board assignments from this meeting.”

The system may then create/end BoardAssignments.

It must not silently mutate board data before explicit confirmation.
