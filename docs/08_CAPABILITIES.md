# Capabilities model — draft

WordPress roles are containers. Domain authorization should use capabilities.

## Candidate capabilities

General:
- `manage_association`

Members:
- `view_members`
- `edit_members`
- `export_members`
- `erase_member_data`

Board:
- `manage_board`

Meetings:
- `view_internal_meetings`
- `manage_meetings`
- `record_meeting`
- `finalize_minutes`
- `publish_minutes`

Documents:
- `manage_documents`
- `view_board_documents`

Future:
- `manage_fees`
- `send_member_mail`

Names are provisional.

## Principles

- least privilege
- every state-changing server action checks authorization
- REST permission callbacks must enforce the same domain rules as classic admin actions
- nonce checks do not replace capability checks
- client-side UI hiding does not provide authorization
- document download authorization must be server-side when content is protected

## Example role intent

### Secretary
May:
- manage meetings
- record notes
- draft minutes
- manage ordinary meeting documents

Should not automatically:
- install plugins
- edit themes
- manage all WordPress users
- manage fees

### Treasurer
May eventually:
- view relevant member identity/contact data
- manage fees

Should not automatically:
- finalize minutes
- manage themes/plugins

## Design task

Create a matrix:

`Operation × capability × object state × object visibility`

and use that matrix in tests.
