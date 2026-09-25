# Locked and provisional decisions

Status terminology:
- **LOCKED** — do not change without explicit project-owner approval.
- **PROVISIONAL** — current direction, may change after design review.
- **OPEN** — deliberately unresolved.

## Product decisions

| Decision | Status |
|---|---|
| Self-hosted WordPress plugin | LOCKED |
| Open source base product | LOCKED |
| No mandatory SaaS | LOCKED |
| No trackers or ads | LOCKED |
| Member is not the same as `wp_user` | LOCKED |
| One WordPress installation represents one association in the first major version | PROVISIONAL |
| WordPress continues to own pages, posts, media, menus, theme and general site design | LOCKED |
| Association plugin owns structured association data and association logic | LOCKED |
| Historical membership and board data matter | LOCKED |
| Meetings are an active work surface, not merely an archive | LOCKED |
| Decisions should be structured data | LOCKED |
| Finalized/adjusted minutes may not be silently overwritten | LOCKED |
| Print and PDF export are core meeting features | LOCKED |
| A scanned signed hard copy can be attached to the finalized minutes | LOCKED |
| Manual physical signatures are sufficient for MVP; e-signing is not required | LOCKED |
| WordPress Media Library should be used for files where appropriate | LOCKED |
| Capabilities, not only broad WordPress roles, control association actions | LOCKED |
| Swedish and English internationalization from the beginning | LOCKED |
| License is GPL-2.0-or-later | LOCKED |
| A board assignment requires an active membership that covers the assignment dates | LOCKED |
| When a signed hard copy and the finalized minutes differ, the signed copy is the archival original | LOCKED |
| Which roles may finalize minutes is an association setting, not a fixed rule | LOCKED |
| Retention of personal data and audit events is an association setting. Default is 5 years | LOCKED |
| A membership number belongs to the membership, not to a person and not to one period | LOCKED |
| Ordinary, youth, family and company memberships are supported | LOCKED |
| A company membership is an organization, not a person | LOCKED |
| Family participants are separate people on one membership | LOCKED |
| Birth date and a Swedish personal identity number are separate. The number is optional | LOCKED |
| The plugin records guardian relationships and approvals. It does not decide that every minor legally requires consent | LOCKED |
| Personal identity numbers are never public output | LOCKED |
| An active individual member with a usable email normally receives a WordPress subscriber account. A known age under 18 is not provisioned automatically. A missing birth date is not treated as proof of minority; that is current v1 implementation behavior, not a legal conclusion | PROVISIONAL |
| WordPress owns member passwords, password reset and authentication. The plugin does not create or reveal a temporary password | PROVISIONAL |
| Ending a membership keeps the Person and any linked WordPress user. Member-only access still requires current active individual membership coverage | PROVISIONAL |
| A matching email does not link a Person to a WordPress user. Company contacts and guardian-only people do not receive member accounts automatically | PROVISIONAL |
| Guardian approval of an account for a minor is future member-portal design and is not locked | OPEN |

"Where appropriate" for the Media Library means public or non-sensitive media, such as the association logo. Protected documents, minutes PDFs, and signed copies use plugin-managed private storage because a Media Library URL is not an authorization check. See ADR-0005 and `docs/PRIVATE_FILES.md`.

## Explicit non-goals for MVP

- bookkeeping/accounting
- payroll
- ERP
- full CRM
- advanced booking engine
- sports competition administration
- full payment platform
- mandatory electronic signatures
- custom WordPress theme
- custom mail transport infrastructure

## Migration history

**PROVISIONAL.** The project owner has not locked this policy.

Before the first public release, a migration implementation may still be corrected while development databases are disposable. Every correction must remain upgrade-safe from all committed development schema states we care about.

After the first public release, released migrations are immutable. All changes use forward migrations only.

## Technical decisions not yet locked

- PHP minimum version. The plugin header and Composer require PHP 8.3 because that is the version the lab and PHPUnit run. This is not an approved lower floor.
- WordPress minimum version. The plugin header says 7.1 because that is the lab version under test. ADR-0008 still proposes PHP 8.2 and WordPress 6.7, and that pair is not tested. Do not treat 6.4 or 6.7 as supported.
- DB engine baseline beyond normal WordPress support
- persistence choice per domain object
- admin application architecture
- REST API shape
- PDF rendering library
- minutes editor implementation
- package/dependency strategy

## Proposed communications / setup-help (not locked)

First-run setup wizard, contextual help, handbook/guides, and optional registered-install bulletin pull are **proposed** product direction only. See `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` and the matching items in `OPEN_QUESTIONS.md`. Do not treat that brief as LOCKED. Any future optional registration or poll must still satisfy locked self-hosted / no-mandatory-SaaS / no-secret-telemetry principles.
