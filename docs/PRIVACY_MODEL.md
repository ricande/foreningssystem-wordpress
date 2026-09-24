# Privacy model

Status: **accepted retention rule, remaining details proposed**. This is not legal advice. The owner locked the retention control on 2026-09-24.

## Retention setting

The association has one setting, in years, for how long personal data and audit events are kept. The default is 5 years.

- Personal contact data is anonymized 5 years after the membership has ended, unless the association changes the setting. An open board assignment cannot outlive the membership, so the clock starts at the end of the membership.
- Audit events are removed 5 years after the event, unless the association changes the setting.
- Finalized minutes and signed originals are not deleted by this timer. The signed copy is the archival original. Anonymizing the person record does not rewrite a signed scan or a finalized minutes snapshot. The privacy eraser reports that those records were kept.
- A personal identity number is removed when that person's contact data is anonymized, and it can also be removed earlier. Membership, board, minutes and decision history stay. A separate retention period for the number is still an open owner decision. Guardian relationships and approval records are not deleted by that removal.
- A privacy export includes the subject's own personal identity number when one is stored. It does not include another person's number. A guardian export may name the child and the approval purpose. It does not include the child's identity number. A child export may name the guardian. It does not include the guardian's identity number or email.

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
| Person contact data | Know who the person is and how to reach them | Yes, to that person | Anonymize direct identifiers. Block full row deletion while governance snapshots name them, and report what was kept | The retention setting, default 5 years after the membership ends, then anonymize |
| Membership | Record that a period happened | Status, type, dates, membership number | Keep the period. Remove it from active lists | Keep the period. Identifiers follow the person retention setting |
| Board assignment | Answer who held an office | Role, dates, person reference | Keep the assignment. Public contact override is removed on anonymization | Keep the assignment dates. The person must have been a member for those dates |
| Meeting participation | Record attendance | That person's attendance rows | Keep the participation row. Display name inside a finalized snapshot is not rewritten | Keep |
| Notes, decision text, minutes body | Run and record the association's work | Do not export other people's minutes as personal data. Mention that minutes may contain the requester's name and were retained | Do not rewrite a finalized snapshot to erase a name. The eraser result says so | Keep finalized revisions. They are not removed by the 5-year setting |
| Documents and signed scans | Archive files that may contain personal data | Include a document only when it is specifically about the requester and the exporter is allowed to read it | Removing a private file is a manual association action, not an automatic eraser success | Signed copies are kept as originals. Other documents follow their own validity dates |
| Audit event | Show who performed a critical action | Export events about the requester, without other people's payloads | Do not store field values in the event | The retention setting, default 5 years after the event, then remove |

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
