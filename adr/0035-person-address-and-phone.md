# ADR-0035: A person has a street address, postal code, city and phone

**Status:** Accepted. The project owner decided the fields and their handling on 2026-10-21.  
**Date:** 2026-10-21

## Context

A person (`assoc_person`) had first name, last name, email, birth date, status, and an optional WordPress user link. ADR-0026 recorded that the spreadsheet import ignores phone, street address, postal code, and city, because Person had no such fields. `docs/07_PRIVACY_GDPR.md` lists address, postal code, city, and phone as possible contact data and asks that each field be justified.

Associations send notices for the annual meeting and other mailings by post to members without email, and they phone members. A member list without address and phone does not cover that. On 2026-10-21 the project owner decided that street address, postal code, city, and phone are part of the member register. The owner also decided that these fields are ordinary contact data. They are not a special category and need no handling beyond what other contact data gets.

## Decision

1. Schema 18 adds four columns to `assoc_person`: `street_address` varchar(190), `postal_code` varchar(20), `city` varchar(100), and `phone` varchar(40). Each is `NOT NULL DEFAULT ''`. Existing people get empty values. The migration adds a missing column and leaves an existing one as it is.
2. The domain value `ContactDetails` holds the four parts. Every part is optional free text, so an address abroad fits. Spaces are trimmed and runs of whitespace collapse to one space. Lengths count characters. A phone number may contain digits, spaces, dashes, parentheses, and one leading plus sign. Postal codes are not checked against a Swedish format.
3. **Ordinary contact data, as the owner decided.** The fields need no separate capability, no encryption, and no separate retention. `view_members` reads them and `edit_members` changes them, as for email.
4. They are still personal data, so they follow the same path as email:
   - the WordPress privacy export lists them
   - anonymization (erase and retention) empties them
   - an edit leaves a value-free audit event, `contact_address_changed` or `contact_phone_changed` (ADR-0032)
   - the form version that stops a stale form from overwriting another officer's edit (ADR-0031) covers them
5. The members screen shows and edits the fields on a new person, a new family participant, and the person form. City and phone are searchable. A phone number also matches without its spaces and dashes.
6. The spreadsheet import maps the headings Adress, Gatuadress, Postnummer, Ort, Telefon, Mobil, and their English names. These columns are no longer reported as ignored. A row with an invalid phone number is reported on its line and not imported.
7. The member files carry the fields (ADR-0030):
   - The plain file has four more columns after `ended_on`: `street_address;postal_code;city;phone`. A file with the earlier nine columns still imports.
   - A participant row in the structured file has the four parts after `person_status`. A row without them imports with no address or phone.
   - A file does not change a person who is already in the register, as before.

## Consequences

- An open person form from before the upgrade is refused once with "someone else saved this person", because its form version does not cover the new fields. The officer reloads the form.
- Company memberships keep their own `postal_address` on the organization. Company contacts are people and can have their own address.
- Guardians and company contacts get their address from the person form after they are created. Their quick-create forms are unchanged.
- Mina sidor does not show or edit the fields. Member self-service is not implemented (`docs/MEMBER_AREA.md`).
