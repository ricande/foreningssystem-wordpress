# Data architecture — decision framework

No persistence choice is locked yet.

## Rule

Do not ask “what is easiest in WordPress?”

Ask:
- what are the entity's relationships?
- does it require historical queries?
- how many rows may exist?
- does it need strong uniqueness/integrity?
- does it need revisions?
- does it need a public permalink?
- does it need WordPress editing UX?
- does it contain private data?
- how will it be queried and indexed?
- what happens on uninstall/export/migration?

## Likely candidates to evaluate for custom tables

These are hypotheses, not locked decisions:
- Person
- Membership / MembershipPeriod
- BoardAssignment
- MeetingParticipant
- Decision
- ActionItem
- audit events

Potential reasons:
- relational structure
- privacy
- history
- queryability
- avoiding postmeta scaling/pathology

## Objects that may benefit from WordPress-native content concepts

Evaluate, do not assume:
- Meeting
- Minutes
- Document metadata
- Activity

Potential advantages of CPT:
- revisions
- block editor
- permissions ecosystem
- public permalinks
- admin list tables

Potential disadvantages:
- post/postmeta semantics may fit poorly
- private domain relations can become awkward
- metadata joins/query complexity
- unwanted public exposure assumptions

## Media

Use Media Library for files where appropriate, but separate:
- storage reference
- association metadata
- access policy

Private file delivery is a distinct security problem.

## Options/settings

Use for low-volume site-level configuration, not as a generic database.

## Decision deliverable

Before schema implementation, produce a table:

| Domain object | Storage option | Alternatives considered | Why | Query/index needs | Privacy notes | Migration notes |
|---|---|---|---|---|---|---|

Then create ADR(s).
