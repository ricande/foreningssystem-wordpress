# Glossary

Status: **proposed refinement** of `docs/03_GLOSSARY_DRAFT.md`. Definitions here are recommendations. They are not locked until the project owner accepts them.

## Association

The single organization represented by one WordPress installation in the first major version.

One installation representing one association is still **PROVISIONAL** in `DECISIONS.md`. The model below assumes that constraint.

## Person

A human being known to the association.

A Person is not a member and not a WordPress user. Membership and login are separate relations.

Recommended contact fields live on the Person: name, postal address, email, phone, optional birth date, contact preferences, and internal notes. A national identity number is not a field.

## Membership

A time-bounded relationship between one Person and the Association.

A Person may have zero, one, or many Membership periods. Re-entry creates a new period. It does not reuse or reopen a closed period in place.

Recommended attributes: membership number, type, status, start, end, and optional membership-year label.

## Member

A human-facing word for a Person whose relevant Membership is active. Do not use Member as a table or class name.

## Membership year

The association's configured fee or membership period. It may differ from the calendar year. Dates on a Membership are the source of truth; the year label is metadata.

## WordPress user link

An optional zero-or-one relation from a Person to a `wp_user`.

Most people in the register will never have an account. Linking an account does not by itself grant association capabilities.

## Board role

A defined office such as chair, treasurer, secretary, alternate, auditor, or election-committee member. Roles are association-defined, not a fixed Swedish legal list.

## Board assignment

A Person holding a Board role for a date interval.

A Person is not "the treasurer". The assignment answers who, which role, from when, until when, and which optional term label applies.

A public role contact, such as a role mailbox, belongs to the assignment. It is not a substitute for the Person's private contact details.

## Meeting type

A category such as board meeting, annual meeting, extraordinary annual meeting, member meeting, or working meeting.

## Meeting template

A reusable agenda structure and meeting configuration. Annual-meeting templates follow the association's own bylaws. No universal Swedish legal agenda is hardcoded.

## Meeting

One scheduled occurrence, with its own lifecycle. It is operational working data, not an uploaded PDF.

## Meeting participant

A Person's participation in one Meeting, including a presence category such as present, absent, or co-opted, and an optional meeting function such as chair or adjuster for that day.

## Agenda item

A numbered point on one Meeting's agenda. Numbering is automatic, with a stored manual override when the secretary needs one.

## Meeting note

Working text attached to an agenda item or the meeting. Notes are not the legal minutes.

## Decision

A formal structured outcome tied to a Meeting and usually an agenda item. It has its own identity, text, responsible person, deadline, and follow-up status.

The text stored inside a finalized minutes revision is a snapshot. Later follow-up status may change without rewriting that snapshot.

## Action item

A follow-up task. It is not a formal decision. It may have an assignee, due date, status, and a source agenda item.

## Minutes

The logical minutes document for one Meeting. It owns one or more revisions.

## Minutes revision

One concrete version of the minutes. A finalized revision is immutable. A correction is a new revision that points at the revision it corrects.

## Generated document

A rendered file, normally a PDF, produced from one specific minutes revision.

## Signed document

An uploaded scan of a manually signed paper copy, linked to one specific finalized revision. It is evidence of the paper act. It does not replace the revision.

## Document

An association file with metadata and a visibility level. Minutes files are documents of a specific kind, not a second unrelated archive.

## Publication visibility

Who may see a document or a published meeting fact.

Recommended levels: `public`, `member`, `board`, `administrator`. Exact enforcement for `member` depends on whether the viewer has a linked Person with an active Membership. That check is still a recommendation.

## Audit event

A retained security or history event. It records who did what to which object. It does not store copies of sensitive field values.
