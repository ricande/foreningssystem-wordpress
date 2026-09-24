# Privacy model

Status: **proposed product policy**, not legal advice. It turns `docs/07_PRIVACY_GDPR.md` into per-object behavior. Retention periods are recommendations for the owner to accept or replace. The plugin must not pretend to be the association's legal assessment.

## Operations that must stay distinct

| Operation | Effect |
|---|---|
| End membership | Sets end date and status `ended`. Person, board history, and minutes stay |
| Archive | Hides a closed period or old document from everyday lists. Rows remain |
| Anonymize person | Replaces direct identifiers, removes the WordPress user link, keeps an internal id so history still joins |
| Erase personal data | WordPress privacy eraser path. May be partial |
| Delete a row | Not offered for Person, Membership, Board assignment, or a finalized revision |

The interface copy uses those words. It does not use "delete member" as a single button.

## Per object

| Object | Purpose | Export | Erase / anonymize | Recommended retention |
|---|---|---|---|---|
| Person contact data | Know who the person is and how to reach them | Yes, to that person | Anonymize direct identifiers. Block full row deletion while governance snapshots name them, and report what was kept | While the membership relationship or a governance role is relevant, then anonymize on a justified request |
| Membership | Record that a period happened | Status, type, dates, membership number | Keep the period. Remove it from active lists | Keep with the historical record |
| Board assignment | Answer who held an office | Role, dates, person reference | Keep the assignment. Public contact override is removed on anonymization | Keep |
| Meeting participation | Record attendance | That person's attendance rows | Keep the participation row. Display name inside a finalized snapshot is not rewritten | Keep |
| Notes, decision text, minutes body | Run and record the association's work | Do not export other people's minutes as personal data. Mention that minutes may contain the requester's name and were retained | Do not rewrite a finalized snapshot to erase a name. The eraser result says so | Keep finalized revisions |
| Documents and signed scans | Archive files that may contain personal data | Include a document only when it is specifically about the requester and the exporter is allowed to read it | Removing a private file is a manual association action, not an automatic eraser success | Follow the document's own validity dates, then archive |
| Audit event | Show who performed a critical action | Export events about the requester, without other people's payloads | Do not store field values in the event. Keep the event | 24 months, then drop or reduce to object id plus action. Owner may choose longer |

National identity numbers are out of the schema.

## WordPress exporter and eraser

Register exporter and eraser callbacks when a Person is linked to the requesting `wp_user`, or when the request email matches the Person email.

Export:

- person contact fields
- membership periods
- board assignments and public role contact
- the user's own meeting-participation summary

Do not export the whole member list, other people's contact details, or full minutes.

Erase:

- anonymize the Person's direct identifiers
- clear private notes and contact preferences
- unlink `wp_user`
- remove a public role contact that is a personal address

Report as retained, not erased:

- membership periods
- board assignment dates and roles
- names already copied into a finalized minutes snapshot
- signed scans, unless an officer removes that file in a separate explicit action

The eraser response must say which parts were anonymized and which were kept.

## Audit events worth the privacy cost

- finalize a minutes revision
- create a correction revision
- replace the current signed copy
- export members
- anonymize or run the privacy eraser
- change who holds `manage_association` or `finalize_minutes`

Do not log email bodies, notes, or full decision text in the audit row.
