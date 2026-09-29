# ADR-0026: A foreign member spreadsheet is checked before it is saved

**Status:** Accepted  
**Date:** 2026-09-29

## Context

ADR-0011 and ADR-0020 describe the plugin's own member files. A treasurer also arrives with a spreadsheet from another register. That file does not have the plugin's header, and it must not become a second membership model.

Person still has first name, last name, optional email, status, optional birth date, and an optional WordPress user link. Membership still has a unique number, one of the four kinds, and a period. Phone, street address, postal code, and city are not person fields. A personal identity number stays in its own table, unencrypted, behind its own capability (ADR-0021). This import does not change that storage.

## Decision

Association → Members gains a spreadsheet wizard beside the existing exact-file import. The exact-file import is unchanged, including its rule that one matching email can receive a new membership.

The wizard requires `edit_members`. It parses with PHP's CSV reader, lets the officer correct comma or semicolon, and suggests a mapping onto the fields that exist. The suggestion can be changed. Unknown columns stay unmapped. A personal-identity column is ignored and its values are not copied into the preview, the result, or a transient. Phone, address, postal code, city, and a single full-name column are not imported, because there is no field for them and a full name is not split.

Preview only reads. Confirm reads the stored file again and validates every row again. Each ready row creates one person and one ordinary, youth, or family membership through the existing repositories, inside the same per-row transaction the member file uses. Company rows are rejected and belong in the structured file. An unknown membership type is an error. An empty membership status is shown in the preview as active, and an empty person status as known. A youth row without a birth date is rejected. Dates are `YYYY-MM-DD` or `DD.MM.YYYY`.

A row is skipped, and explained, when the membership number already exists, the number is repeated in the file, the email already belongs to one person, or the email is repeated in the file. The same name is not a duplicate. Nothing is merged. The wizard does not set `wp_user_id` and does not create a WordPress login.

The uploaded file is stored under a random name outside the public tree, not under the browser's filename. It is deleted on confirm, on a failed confirm, on cancel, or after one hour. Cleanup deletes only a regular file whose name is that upload's 32-character hex token inside the officer's own directory. It does not follow a symlink. A second confirm of the same file reports the earlier result and does not write again. A membership number that already exists is also skipped if the file is imported twice.

Limits are 2 MiB, 2 000 data rows, 40 columns, and 500 characters in a cell. A longer cell is an error and the value is not kept. A file with more than 2 000 data rows is refused before it is stored, and the admin message states that number.

## Consequences

Positive:

- An officer can see ready rows, warnings, errors, and possible duplicates before anything is saved.
- The round-trip file and the structured file keep their current meaning.
- A shared family email cannot attach a spreadsheet row to the wrong person.

Negative:

- A spreadsheet row that really is an existing person is not updated. The officer handles that case.
- The temporary file can contain an ignored personal-identity column until it is deleted. It is not written into the result or into ordinary logs.
- Personal identity numbers remain unencrypted. This feature does not add a cipher.
