# Capability matrix

Status: **proposed**. Names match the candidates in `docs/08_CAPABILITIES.md` and are not locked. WordPress roles only bundle these capabilities. Every state-changing request checks the capability on the server. A nonce does not replace that check. Hiding a button does not authorize anything.

## Capabilities

| Capability | Allows |
|---|---|
| `manage_association` | Association profile, role definitions, capability assignment for association officers |
| `view_members` | Read person and membership records |
| `edit_members` | Create and update people and memberships, including ending a membership |
| `export_members` | Export member data |
| `erase_member_data` | Anonymize a person and process privacy-eraser decisions that go beyond the core WordPress user |
| `manage_board` | Create and end board assignments and role definitions |
| `view_internal_meetings` | Read unpublished meetings, notes, and board-visible documents |
| `manage_meetings` | Plan meetings, templates, and agenda |
| `record_meeting` | Notes, decisions, action items, and minutes drafts |
| `finalize_minutes` | Finalize a revision and open a correction revision |
| `publish_minutes` | Show or hide a finalized revision on the public site |
| `manage_documents` | Document metadata and uploads |
| `view_board_documents` | Read documents marked board |
| `manage_fees` | Reserved. Not an MVP screen |
| `send_member_mail` | Reserved. Not an MVP mailer |

`manage_association` does not imply `install_plugins`, `edit_themes`, or `manage_options`.

## Suggested bundles

These bundles are the initial contents of an association setting. They are not a locked rule about who may finalize minutes. The association can grant or remove `finalize_minutes` per role.

| Association role | Capabilities |
|---|---|
| Secretary | `view_internal_meetings`, `manage_meetings`, `record_meeting`, `manage_documents`, `view_board_documents`, `view_members` |
| Chair | Secretary capabilities plus `manage_board`, `finalize_minutes`, `publish_minutes` |
| Treasurer | `view_members`, `export_members`. `manage_fees` only when fees exist |
| Board member | `view_internal_meetings`, `view_board_documents`, `view_members` |
| Member with a linked account | No association capability by default. Member-visible content is checked through an active Membership, not through a capability |

The chair bundle includes `finalize_minutes` only as the starting suggestion. A secretary, or any other role, can receive it in settings. The capability check stays the same either way.

## Operation matrix

Visibility values: public, member, board, administrator. "State" is the meeting or revision state from `docs/MEETING_STATE_MACHINE.md`.

| Operation | Capability | State guard | Visibility guard |
|---|---|---|---|
| View association overview counts | `view_members` or `view_internal_meetings` | — | Dashboard is an admin screen |
| Create or edit a person | `edit_members` | — | Admin only |
| End a membership | `edit_members` | Period is not already ended | Admin only |
| Anonymize a person | `erase_member_data` | Explicit confirmation | Admin only |
| Export members | `export_members` | — | Admin only |
| Replace a board role holder | `manage_board` | The person has an active membership covering the new dates. The previous open assignment for that role is ended, not deleted | Admin only |
| View current board block | none for public fields | Assignment covers today | Public block shows name, role, and public role contact only |
| Create a meeting | `manage_meetings` | Starts in `planned` | Admin only |
| Edit agenda | `manage_meetings` or `record_meeting` | `planned`, `in_progress`, or `held` | Admin only |
| Take notes, add decision or action | `record_meeting` | Before finalization of the current revision | Admin only |
| Create minutes draft | `record_meeting` | Meeting is `held` and has no open draft | Admin only |
| Edit draft body | `record_meeting` | Revision is `draft` or `under_adjustment` | Admin only |
| Finalize revision | `finalize_minutes` | Revision is `under_adjustment` | Admin only |
| Open correction revision | `finalize_minutes` | A finalized revision exists | Admin only |
| Print or export PDF | `record_meeting` for a draft, `view_internal_meetings` for a finalized internal copy | PDF of a finalized revision is generated from the snapshot | Download follows the revision's visibility |
| Upload or replace signed copy | `manage_documents` and `finalize_minutes` | Revision is `finalized` | File is board or administrator until someone publishes |
| Publish or unpublish minutes | `publish_minutes` | Revision is `finalized` | Sets `public` or returns it to `board` |
| View a public minutes block | none | Latest published finalized revision | `public` only |
| View a member-only document | linked Person has an active Membership | — | `member` |
| View a board document | `view_board_documents` | — | `board` |
| Download a non-public file | same as viewing that visibility | — | Server-side stream. The upload URL is not the access check |

REST permission callbacks, when REST exists, use this same matrix. There is no separate, weaker REST policy.
