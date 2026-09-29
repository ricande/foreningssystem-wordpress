# ADR-0027: Removing unused custom board roles and meeting types

**Status:** Accepted

**Date:** 2026-09-29

## Context

Setup and Settings can add a custom board role or meeting type, but an officer who adds the wrong one during setup cannot take it back. The earlier note that roles and meeting types are never deleted blocked that correction. Built-in rows, board history, meetings, and minutes permissions must still stay intact.

Minutes permissions are stored on association roles (`assoc_chair`, `assoc_secretary`, `assoc_treasurer`, `assoc_board_member`) in the existing role-capability setting. A custom board role is a structure row. It is not one of those four roles, and holding it is not the same as being appointed to adjust one meeting.

## Decision

- A built-in board role or meeting type cannot be removed.
- A custom board role can be removed while no board assignment uses it, including closed history, and while it is not selected to finalize or publish minutes.
- A custom meeting type can be removed while no meeting and no meeting template uses it.
- If removal is refused, the screen says why. The same rule is enforced in `BoardRoleDefinitions` and `MeetingTypeDefinitions`, not only in the wizard markup. `manage_association` and the admin nonce still apply.
- A custom board role may be granted `finalize_minutes` and `publish_minutes` through the existing minutes setting, beside the four association roles. The grant does not give that role any other association capability. While the grant is stored, the role counts as used and cannot be removed. Clearing the grant removes the reference, so the role can be removed and the synced WordPress role loses those capabilities.
- Choosing a role for that permission does not appoint who adjusts a particular meeting. The association's bylaws and the meeting's decision do that.

## Consequences

- Schema stays 16. No migration.
- Plugin version stays 0.1.0.
- Settings and the setup wizard share the removal and the minutes choice list.
- Future hardening, not part of this decision's implementation: `used()` and `remove()` run in one transaction, and the referring tables have indexes rather than foreign keys. A concurrent insert or save can therefore leave a reference to a structure row that was just removed. That applies to a board assignment and a board role, a meeting and a meeting type, a meeting template and a meeting type, and, separately, the minutes policy stored in `wp_options`. The model is unchanged.
