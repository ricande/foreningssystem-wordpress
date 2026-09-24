# ADR-0016: Personal identity numbers

**Status:** Accepted for storage separation. Encryption remains open in ADR-0021.  
**Date:** 2026-09-24

## Context

Youth clubs may need a Swedish personal identity number. Birth date is ordinary data and is enough for age. The number must not sit on the person row or in public output.

## Decision

The number is optional and stored in `assoc_personal_identity` with a purpose, a basis note, a collection date and the user who recorded it. Canonical form is `YYYYMMDD-XXXX`. Ten-digit input uses the hyphen for the latest century that is not after today, and `+` for the century before that. The birth date is not copied from the number.

Validation is the date check and the Luhn checksum, including coordination numbers whose day is 61–91. There is no external validation service. The value is not a person id, a username, a URL, a log line or a public field.

Removing the number does not delete the person, membership, board history, minutes or decisions.
