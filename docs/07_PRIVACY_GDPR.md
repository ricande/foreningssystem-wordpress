# Privacy and GDPR model — design requirements

This document is a product/architecture checklist, not legal advice.

## Principles

- data minimization
- purpose limitation
- least privilege
- explicit lifecycle
- export where appropriate
- erasure/anonymization where appropriate
- historical integrity where justified
- no unnecessary sensitive identifiers

National personal identity number must not be a baseline requirement.

## Data categories

### Person/contact data
Examples:
- name
- address
- postal code/city/country
- email
- phone
- optional birth date
- contact preferences
- notes

Cursor must challenge every field:
“Why is this needed?”

### Membership data
- membership number
- membership periods
- type/status
- dates

### Governance data
- board assignments
- meeting participation
- responsibility for decisions/actions

### Documents
May contain personal data not visible in structured fields.

## Lifecycle questions

For each object define:
- purpose
- creation event
- update rules
- visibility
- retention
- archival rules
- export behavior
- erase/anonymize behavior
- dependencies preventing deletion

## Important distinction

These are not synonyms:
- end membership
- archive membership
- anonymize person
- erase personal data
- delete a database row

The UI and APIs must use precise language.

## WordPress privacy integration

Evaluate use of WordPress personal data exporter/eraser hooks where suitable.

Do not promise automatic erasure of data that the association must intentionally retain; instead design explicit policies and explain blocked/partial erase results.

## Audit log

Log only events that justify the privacy cost.

Candidate critical events:
- finalizing minutes
- creating a replacement/correction revision
- exporting member data
- changing high-privilege association capabilities
- replacing a signed archival copy
- destructive/anonymizing member operations

Do not log sensitive field values merely “because audit”.
