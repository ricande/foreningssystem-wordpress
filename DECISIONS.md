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

- PHP minimum version
- WordPress minimum version
- DB engine baseline beyond normal WordPress support
- persistence choice per domain object
- admin application architecture
- REST API shape
- PDF rendering library
- minutes editor implementation
- package/dependency strategy
