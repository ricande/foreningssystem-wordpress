# ADR-0032: A contact edit leaves an audit event per changed field, without values

**Status:** Proposed (implemented, waiting for owner review)  
**Date:** 2026-10-21

## Context

`PeopleService::updateContact` replaces a person's first name, last name, email, and birth date on the `assoc_person` row. ADR-0031 added a `contact_version` check, so a stale form can no longer overwrite another officer's edit. But nothing records that an edit happened. Afterwards the association cannot answer "when did this address change, and who changed it?" Historical data is a LOCKED product principle.

The privacy rules limit what such a record may hold:

- `docs/07_PRIVACY_GDPR.md`: do not log sensitive field values merely "because audit".
- `docs/PRIVACY_MODEL.md`: an audit event shows who performed a critical action. Do not store field values in the event. Remove it after the retention setting, default 5 years after the event. Export events about the requester.
- `docs/DATA_STORAGE_PLAN.md`: `assoc_audit_event` holds object type and id, action, actor, and time. No field payloads.

## Options considered

### Option A — One audit event per changed field, no values (chosen)

On save, compare the stored person with the edited one. For each part that changed, write one row to the existing `assoc_audit_event` table: object type `person`, the person id, the acting WordPress user, the time, and one of these actions:

- `contact_name_changed` (first or last name)
- `contact_email_changed`
- `contact_birth_date_changed`

Pros:
- Answers what kind of detail changed, when, and by whom.
- Stores no old or new value, so the trail itself holds no contact data to erase.
- Reuses the table, the retention job (`AuditLog::forgetOnOrBefore`), and the patterns of the personal identity events. No migration. Schema stays 17.
- Written in the same transaction and under the same register lock as the edit, so an edit and its event are saved together or not at all.

Cons:
- The earlier value cannot be read back. An officer who mistypes an address cannot see what it used to be.
- One row per changed part instead of one row per save.

### Option B — A contact history table with earlier values

A new table, for example `assoc_person_contact_history`, with the earlier name, email, and birth date, the time, and the actor.

Pros:
- An earlier address or name can be shown and restored.

Cons:
- Keeps copies of personal data that the person row no longer holds. Every copy must be exported on request, cleared by the eraser and by retention, and kept out of other screens.
- A new table and migration, and a second place where an email can match a privacy request.
- The privacy model says not to keep values in an audit trail. A table of old values is that trail under another name.
- No current workflow needs an old value. Membership, board, and meeting history already keep their own facts. A finalized minutes snapshot keeps the name it was written with.

### Option C — Keep the earlier values inside the audit row

Rejected. It breaks the "no field values in the event" rule.

## Decision

Option A.

- `updateContact` takes the acting WordPress user id. It writes one event for each part that changed: name, email, birth date. A save that changes nothing writes no event. A rejected save (stale form, future birth date, busy register, failed write) writes no event, because the event is in the same transaction.
- The comparison is exact after trimming, so a change of letter case counts as a change.
- The event has no field values. The action names which part changed.
- Retention removes the events with the other audit events, after the retention setting.
- Erase and anonymization leave the events in place. They name no value, and the person row they point at is anonymized. Anonymization records its own `anonymize_person` event, as before.
- The privacy export lists the requester's contact changes as date and part changed (name, email, birth date). It does not include who made the change, because that is another person's data.

## Consequences

Positive:

- A contact edit is traceable without keeping old contact data.
- Nothing new to erase. Retention already covers the table.

Negative:

- The earlier value is gone. If the association later needs to restore earlier values, that needs a new decision on Option B, with its own retention and erase rules.
- Email changes made by other code paths are not covered by this ADR. Today `updateContact` is the only code that changes these fields on a stored person. Anonymization and imports do not overwrite stored contact details.
