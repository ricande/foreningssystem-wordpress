# Glossary — draft

Cursor should refine this before implementation.

## Association
The organization represented by the WordPress installation.

## Person
A human being known to the association.

A Person is not necessarily a member and is not necessarily a WordPress user.

## Membership
A relationship between a Person and the Association.

Potentially contains:
- membership number
- type
- status
- start/end
- membership period/year

## Member
Human-facing term for a Person with a relevant Membership state. Avoid using it as an overloaded persistence concept until the domain model is locked.

## WordPressUserLink
Optional relation between a Person and a WordPress `wp_user`.

## BoardRole
A role definition such as chairperson, treasurer or secretary.

## BoardAssignment
A Person holding a BoardRole during a defined period.

## MeetingType
Category such as board meeting, annual meeting, extraordinary annual meeting or member meeting.

## MeetingTemplate
Reusable agenda structure and meeting configuration.

## Meeting
A specific meeting occurrence.

## MeetingParticipant
A person's participation in a Meeting, including role/presence category.

## AgendaItem
A structured point in the meeting agenda/minutes.

## MeetingNote
Working notes associated with a meeting or agenda item.

## Decision
A formal structured decision associated with a meeting/agenda item.

## ActionItem
A follow-up task that is not necessarily a formal decision.

## Minutes
Logical minutes document associated with a meeting.

## MinutesRevision
A concrete revision of minutes. A finalized/adjusted revision is historical and must not be silently overwritten.

## GeneratedDocument
A generated representation, such as a PDF of a MinutesRevision.

## SignedDocument
A scanned/uploaded signed hard-copy representation associated with a finalized revision.

## Document
General association document with metadata and access policy.

## PublicationVisibility
Access classification, e.g. public, member, board, administrator. Exact semantics remain open.

## AuditEvent
Security/history event captured when justified.
