# Data storage plan

Status: **proposed**. This is the table required by `docs/09_DATA_ARCHITECTURE.md`. It is not permission to create the schema. ADR 0003 records the storage recommendation. The owner can reject it before any migration runs.

Custom table names below use `{$wpdb->prefix}assoc_`. The prefix is provisional.

| Domain object | Recommended storage | Alternatives considered | Why | Query / index needs | Privacy notes | Migration notes |
|---|---|---|---|---|---|---|
| Association profile | Options | Custom table with one row | One small config object: name, organization number, address, language, logo attachment id, membership-year start | Read on admin and blocks | Organization number is not personal data by itself | Versioned option keys, not a blob that silent upgrades rewrite |
| Person | Custom table `assoc_person` | `wp_users`, CPT, usermeta | Hundreds of people, no accounts, private fields, historical queries | Email, membership number via membership table, name | Direct identifiers. Exporter and anonymize operate here | Adding a column needs a default or a backfill. Never drop the table on update |
| Membership | Custom table `assoc_membership` | A column on the person | Stable number, kind, optional organization | membership number unique | Keep | One number per membership, not per period |
| Membership period | Custom table `assoc_membership_period` | Columns on the membership row | Several periods per membership | membership id, start/end, status | Keep | Schema 14 rows were copied one-to-one and were not merged |
| Membership participant | Custom table `assoc_membership_participant` | A single person id on the membership | Family and company contacts | role `member` or `contact` | Keep | Only `member` covers board eligibility |
| Organization | Custom table `assoc_organization` | A fake person | Company membership | organization number | Keep | Not a personal identity number |
| Personal identity | Custom table `assoc_personal_identity` | A column on the person | Optional, purpose-bound, capability gated | person id unique | Remove on anonymization or explicit removal | Not encrypted yet. See ADR-0021 |
| Guardian relationship and approval | Custom tables `assoc_guardian_relationship`, `assoc_guardian_approval` | Inferred from a family membership | Explicit relationship and an approval record | child and guardian person ids | Keep the rows when the identifier is removed | The record is not a legal determination |
| WordPress user link | Column `wp_user_id` on person, nullable unique | Separate table | Zero or one link | unique `wp_user_id` | Unlink on anonymize | Null is the normal case |
| Board role | Custom table `assoc_board_role` | Taxonomy | Small, ordered, association-defined list | slug unique | None | Seed suggested roles. Do not assume they match every association |
| Board assignment | Custom table `assoc_board_assignment` | CPT, user meta | Date-range history is the product | role_id + dates, person_id | Public contact override is personal if it is a person's address | End date null means current |
| Meeting type and template | Custom tables | CPT for templates | Templates are configuration, not public posts | type_id | Template text is not personal | Bylaw templates are data, not PHP |
| Meeting | Custom table `assoc_meeting` | CPT | Relational children, privacy, no need for a public post permalink | type, starts_at, status | Header may name a place, not a member list by itself | Status values are an enum in application code |
| Participant, agenda item, note | Custom tables | Post meta | Ordered children and presence categories | meeting_id, position | Notes are personal data | Include-in-minutes is a column on the note |
| Decision and action item | Custom tables | Repeater meta, only text in minutes | Follow-up queries across meetings | meeting_id, status, assignee, due date | Assignee is a person id | Snapshot text is copied onto the revision, not read live after finalization |
| Minutes and revision | Custom tables `assoc_minutes`, `assoc_minutes_revision` | CPT plus WordPress revisions | Immutability, correction chain, and canonical payload do not match post revisions | meeting_id, revision number, state, supersedes | Body is retained even when a person is anonymized | Finalized rows are not updated except for `superseded_by` and file pointers |
| Generated PDF and signed scan | Media Library attachment plus columns on the revision | Files only on the custom table, no Media Library | Locked decision: use the Media Library where it fits | attachment id on the revision | Access policy is plugin data, not the attachment URL | Store source hash of the revision payload next to the PDF attachment id |
| General document | Custom table `assoc_document` plus attachment id | CPT | Visibility, validity dates, categories | visibility, category, valid_from | File bytes may contain personal data | Category can be a small lookup table, not a public taxonomy |
| Audit event | Custom table `assoc_audit_event` | External log, post type | Query by object and action, retention job | object type + id, created_at, action | No field payloads | A retention task deletes or reduces old rows. It is not a schema migration |
| Public pages | Normal WordPress pages and dynamic blocks | Copying board HTML into post content | WordPress already owns pages | — | Blocks must not serialize private fields into post content | Block markup stores configuration only |

## Options versus tables

Use options for the association profile, the schema version, and capability-to-role defaults. Do not store people, meetings, or documents in options.

## What this rejects

- Members as `wp_users`
- People, memberships, or board history as custom post types
- Minutes as WordPress post revisions
- "The attachment is private because the post is private"

CPT remains appropriate for ordinary WordPress content the association writes itself, such as news posts. That content is not the system of record for a decision or a board term.

## Activities and fees

When those areas are built, prefer custom tables that reference `person_id` and dates. Do not add them to the first schema "so the prefix exists".
