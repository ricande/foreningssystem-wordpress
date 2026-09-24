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

"Where appropriate" means public or non-sensitive media, such as the association logo. Protected documents, minutes PDFs, and signed copies use plugin-managed private storage because a Media Library URL is not an authorization check. See ADR-0005 and `docs/PRIVATE_FILES.md`.
| Capabilities, not only broad WordPress roles, control association actions | LOCKED |
| Swedish and English internationalization from the beginning | LOCKED |
| License is GPL-2.0-or-later | LOCKED |
| A board assignment requires an active membership that covers the assignment dates | LOCKED |
| When a signed hard copy and the finalized minutes differ, the signed copy is the archival original | LOCKED |
| Which roles may finalize minutes is an association setting, not a fixed rule | LOCKED |
| Retention of personal data and audit events is an association setting. Default is 5 years | LOCKED |

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
