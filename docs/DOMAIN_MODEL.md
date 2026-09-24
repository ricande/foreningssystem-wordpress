# Domain model

Status: **proposed**. This refines `docs/04_DOMAIN_MODEL_DRAFT.md` and answers the identity questions in `OPEN_QUESTIONS.md` as recommendations. Nothing in this file upgrades a PROVISIONAL or OPEN decision in `DECISIONS.md` to LOCKED.

## Shape

```text
Association
 ├─ Person
 │   ├─ birth date
 │   ├─ optional personal identity record
 │   ├─ guardian relationship [0..n]
 │   └─ WordPress user link [0..1]
 ├─ Organization
 │   └─ company membership
 ├─ Membership
 │   ├─ membership number
 │   ├─ kind
 │   ├─ MembershipParticipant [1..n]
 │   └─ MembershipPeriod [1..n]
 ├─ Board assignment
 └─ Meeting
```

A person, a membership and a membership period are different things. The membership number belongs to the membership. A returning membership opens another period on that same membership. Schema 14 rows are not merged: each old row, which already had its own number, becomes its own membership.

## Recommended answers

| Question | Recommendation | Why |
|---|---|---|
| Is the core model Person → Membership? | Person, membership and period are separate | The number belongs to the membership. Periods record when it was active |
| Multiple membership periods? | Yes | Re-entry and history need separate periods |
| Can a board member, auditor, or election-committee member exist without a current membership? | No. Locked by the owner on 2026-09-24 | Every board role in this model, including auditor and election committee, requires an active membership that covers the assignment dates |
| Where do contact details live? | Private details on Person. Optional public role contact on Board assignment | Public blocks must not default to a private email or phone |
| How is a deceased former member represented? | Person status `deceased`. End the membership. Keep history. Omit the person from current public board output | Deleting the row destroys institutional memory |
| What does "delete member" mean? | The product does not offer one delete action | End membership, anonymize, and erase personal data are different operations |

## Invariants

- A Person may have zero or more memberships. Periods on the same membership must not overlap. A person who counts as a member also cannot have overlapping coverage on two memberships.
- Ending a membership period sets an end date and a terminal status. It does not delete the Person, the membership, or past assignments.
- Only a participant with role `member` counts as a member for the active-member count and for board eligibility. Role `contact` does not. A company contact is a contact.
- A Board assignment has a start date and either an open end or an end date. "Who held this role on date D?" is answered from those dates, not from protocol text.
- The membership period must cover the whole assignment. An assignment cannot be created outside an active membership. Ending a membership ends any open assignment on the same date.
- A term label, membership year, or source meeting may be stored on an assignment. The dates remain the query source.
- An annual meeting may propose board changes. Applying them creates and ends assignments only after an explicit confirmation.
- A finalized minutes revision is a snapshot. Later edits to people, board, notes, decisions, or action status do not change it.
- A signed document points at exactly one finalized revision.
- A file in the Media Library is not private just because plugin metadata says so.

## Membership status

Recommended status values:

- `pending`
- `active`
- `dormant`
- `ended`
- `deceased` is a Person status, not a membership status. The membership that ended because the person died uses `ended`.

`dormant` means the person remains a member under the association's own rules but is not counted as active. The product does not invent the legal meaning of dormant.

## Board history

Replacing the treasurer creates a new assignment and sets the end date on the previous one. The previous row stays.

Public role contact is optional. If it is empty, the public board block shows the person's name and role only.

## What is intentionally not in the model yet

Activities, fees, and mail recipient selection are real product areas in the original brief and are outside MVP. The Person and Membership model must not block them later: a fee record would reference a Person and a membership year, and a recipient query would filter Membership status. Those tables are not part of this baseline.
