# Domain model — draft

This is deliberately technology-neutral.

```text
Association
 ├─ Person
 │   ├─ Membership [0..n]
 │   ├─ WordPressUserLink [0..1]
 │   ├─ BoardAssignment [0..n] ── BoardRole
 │   └─ MeetingParticipant [0..n]
 │
 ├─ MeetingType
 ├─ MeetingTemplate
 ├─ Meeting
 │   ├─ MeetingParticipant
 │   ├─ AgendaItem
 │   │   ├─ MeetingNote
 │   │   ├─ Decision
 │   │   └─ ActionItem
 │   └─ Minutes
 │       └─ MinutesRevision
 │           ├─ GeneratedDocument
 │           └─ SignedDocument
 │
 └─ Document
```

## Modelling intent

### Person and Membership should probably be separate

This supports:
- former members
- re-entry
- non-member board/audit participants
- historical membership periods
- cleaner privacy rules

This remains a decision to validate, not an excuse to start coding.

### BoardAssignment carries time

A Person is not “the treasurer”.

The relation should answer:
- who?
- which role?
- from when?
- until when?
- for which term?

### Meeting is operational

Meeting is not merely metadata around an uploaded PDF.

It has a lifecycle and structured sub-objects.

### Minutes are derived but independent historical artifacts

The system may generate minutes from meeting data, but once a revision is finalized it must be preserved as the version that was actually approved.

Changing current structured data later must not retroactively mutate historical minutes.

## Important invariants to define and test

- one person may have zero or more membership periods
- historical board assignments are retained
- finalized minutes revisions are immutable
- signed copies are linked to a specific finalized revision
- private documents are never made public merely because they exist in Media Library
- state transitions enforce capability and workflow rules
