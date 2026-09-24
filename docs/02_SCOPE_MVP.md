# MVP scope

## MVP objective

Prove that the system can become useful in day-to-day association administration without turning into an ERP.

## In MVP

### Association profile
- name
- organization number, optional
- address/contact details
- language
- logo reference
- current operational/membership period configuration

### People and membership
- person/contact information
- membership identity and number
- membership type/status
- entry/exit dates or membership periods
- optional `wp_user` link
- CSV import/export should be strongly considered before first public release

### Board
- role definitions
- board assignments
- start/end/term
- public role contact information
- history

### Meetings
- meeting types
- templates
- agenda items
- participants
- meeting notes
- decisions
- action items
- workflow/status

### Minutes
- deterministic draft generation from structured meeting data
- editable draft
- review/adjustment state
- finalized revision
- print view
- server-side PDF export
- signature fields
- upload signed hard copy

### Documents
- metadata around association documents
- Media Library usage where safe and appropriate
- visibility level

### Dashboard
At minimum:
- active member count
- current board
- next/last meeting
- open decisions/action items
- latest relevant documents

### Public Gutenberg
Initial blocks should be small and high-value:
- Current board
- Latest board meeting
- Latest minutes
- Document archive
- Member count

### Security/privacy
- capabilities
- privacy lifecycle
- export/erase policy
- critical audit events
- secure private document access

## Explicitly out of MVP

- full membership-fee/payment reconciliation
- Swish/Stripe/PayPal integration
- accounting
- payroll
- full outbound mailing engine
- full member self-service portal
- advanced event/calendar system
- advanced booking
- sports competition administration
- electronic signature providers
- custom theme
