# Meeting state machine

Status: **proposed**. State names in `docs/05_MEETING_WORKFLOW.md` were examples. This file recommends the transitions, who may perform them, and what stays editable. It does not lock the legal meaning of "justerat" for every association.

## Two lifecycles

The meeting and the minutes revision are different objects.

Publication and archive are not states. They are visibility and filing flags on a finalized revision. A meeting can be finalized and still unpublished.

### Meeting

```text
planned ──► in_progress ──► held
   │                           ▲
   └───────────────────────────┘
```

`held` means the sitting is closed. Structured capture may still change until a revision is finalized. Skipping `in_progress` is allowed when the secretary records the meeting afterwards.

### Minutes revision

```text
draft ──► under_adjustment ──► finalized
  ▲              │
  └──────────────┘
                     │
                     └── create correction ──► new draft
```

`finalized` has no path back to `draft` on the same revision. A correction is a new revision with `corrects_revision_id` set. When the new revision is finalized, the previous one stays immutable and gains `superseded_by`.

## Transitions

| From | To | Capability | Notes |
|---|---|---|---|
| planned | in_progress | `record_meeting` | |
| planned | held | `record_meeting` | After-the-fact recording |
| in_progress | held | `record_meeting` | |
| held | first revision `draft` | `record_meeting` | Explicit "create minutes draft" |
| draft | under_adjustment | `record_meeting` | Marks the draft ready for adjusters |
| under_adjustment | draft | `record_meeting` | Send back |
| under_adjustment | finalized | `finalize_minutes` | Irreversible for that revision |
| finalized | new `draft` | `finalize_minutes` | Correction only. Copies the snapshot |

`manage_meetings` may also perform the `record_meeting` transitions. Finalizing stays separate so a note-taker cannot lock history alone.

## What may change

| Data | planned / in_progress / held, no draft | draft / under_adjustment | after finalization |
|---|---|---|---|
| Header, agenda, participants | Yes | Yes | Only as live meeting data. Does not alter the revision |
| Notes | Yes | Yes | Working notes may still be edited. They are outside the snapshot |
| Decisions and action items | Yes | Yes | Follow-up status may change. Text in the snapshot does not |
| Revision body | No revision yet | Yes | No |
| Regenerate draft from structured data | — | Yes, and only after confirmation if the draft was edited by hand | No |

Late agenda items are allowed in `planned` and `in_progress`. During `draft` or `under_adjustment` they require an explicit action and mark the draft stale. They never change a finalized revision.

Agenda numbers are generated in order. A stored override replaces the generated number for display and for the snapshot.

## Minutes body

The first draft is composed on the server from:

- association and meeting header
- attendance
- agenda headings and numbers
- notes marked for inclusion
- decision text
- action items when the template includes them
- signature roles from the template

No AI step is part of this composition.

The revision stores the composed body and a canonical payload of the structured values that were included. Finalization freezes both. Later changes to the live rows do not rewrite the payload.

## Decisions versus action items

A decision is the formal outcome. An action item is a task. One agenda item may have both, either, or neither. A decision keeps a stable identity. Its wording inside a finalized revision is a copy taken at finalization.

Correcting that wording after finalization is a new minutes revision, not an in-place edit. Changing an action item from open to done is ordinary follow-up and does not require a new revision.

## Adjustment

"Finalized" in the product means: this revision is the association's approved text and will not be silently changed.

It does not mean the plugin has certified compliance with a particular country's association law. Signature roles and templates come from the association. Manual signatures are enough for MVP.

## Signed copy and publication

A signed file can be attached only to a finalized revision. Replacing the current signed file is an audited action. The product keeps both the revision and the scan. It does not decide which one wins if a human marks them as inconsistent.

`publish_minutes` may expose a finalized revision through the public blocks. Publishing does not modify the body. Unpublishing hides it. The default publication target is the latest finalized revision that is not superseded.
