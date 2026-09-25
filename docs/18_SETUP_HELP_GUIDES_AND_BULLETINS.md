# Setup, Help, Guides and Registered-install Communications

**Status:** mixed — **First-run Setup Wizard v1 is implemented** (see ADR-0023). Help & Guides, contextual help, and registered-install bulletins remain **proposal only**, not LOCKED.  
**Context:** owner product brief (2026-09-25); wizard implementation approved and shipped as Wizard v1.  
**Implementation:** Wizard only. Do not treat Help/Guides/bulletins as approved for coding.

This document is the canonical home for product communications and onboarding. It does not replace `docs/10_ADMIN_UX.md` (admin navigation and implemented screens). The existing **association Settings** screen (board roles / meeting types) remains the canonical structure editor; the wizard reuses those same services.

---

## Locked principles this builds on

These are already LOCKED in `AGENTS.md` / `DECISIONS.md`. This brief must fit them; it does not reopen them.

| Principle | Implication for this area |
|---|---|
| Self-hosted first; no mandatory SaaS | Core setup, help, and guides work fully offline. Any registration or bulletin service is optional. |
| No ads / trackers / hidden callbacks; no secret external dependencies | No silent phone-home. Any network contact is explicit, documented, and optional. |
| Free/open-source base must be genuinely useful | First-run wizard, contextual help, and handbook are product features of the base product, not paywalled afterthoughts. |
| Person ≠ `wp_user` | Wizard and guides teach Person vs membership vs WordPress account; they must not collapse those concepts. |
| Prefer integration over rebuilding unrelated systems | Integration guides explain adapters and data ownership; they do not invent a second master for the same data. |
| Privacy/GDPR from the beginning | Registration and any optional telemetry must not send member/person data; privacy/unregister must be clear. |

**Fit note (not a decision):** Voluntary registration and optional bulletin *pull* can be compatible with the locked “no telemetry / no secret external dependencies” principle **if** they stay clearly optional, fail-open for core product use, send no member data, are never framed as mandatory telemetry, and never become a hidden dependency. That fit is a design constraint for future ADRs — not approval to ship.

---

## Implemented: First-run Setup Wizard v1

Approved and implemented. Details in ADR-0023 and `docs/10_ADMIN_UX.md`.

**Flow:** Install → activate → Welcome → setup wizard → association configured → Overview → next steps (add members, set up board, plan first meeting).

**Hard constraints (shipped):**

- Reuses Association Profile, Settings (board roles / meeting types), minutes lock/publish, retention, and private-storage warning status. No second settings system.
- Local options only: `assoc_setup_version` (`0`/absent incomplete, `1` = Wizard v1 done), `assoc_setup_step`, `assoc_setup_redirect_pending`. Not tied to schema (still 16).
- Fresh install (schema absent/`0` before activation migration): setup stays incomplete; first-run redirect pending for `manage_association`.
- Existing pre-wizard (schema `> 0`, setup option never written): adopt setup `1`, do not force wizard.
- While incomplete: Association → Get started / Förening → Kom igång. Direct URLs keep page caps. Application services are not blocked.
- Reopen from Settings after complete does not mark incomplete or reset data.

**Wizard steps:** Welcome → Association → Membership (educational) → Board → Meetings → Minutes and documents → Privacy → Complete.

Help & Guides links are **not** included in the completion next steps.

---

## Still proposal only (not implemented)

1. Contextual help per workspace.
2. Complete handbook / Help & Guides under Association.
3. Message center for registered installations.
4. Optional voluntary registration, bulletin pull/poll, signing, and consented install telemetry.

Do not start coding those areas as if approved.

---

## Syfte

Föreningsplugin ska inte bara vara funktionellt. Det ska hjälpa en förening från första installation till dagligt arbete.

Användaren ska inte behöva förstå pluginets interna informationsarkitektur för att komma igång.

Produkten ska därför innehålla fyra sammanhängande delar:

1. **First-run Setup Wizard** — **implemented (v1)**
2. **Kontextuell hjälp** — proposal
3. **Komplett handbok / guider** — proposal
4. **Meddelandecenter för registrerade installationer** — proposal

---

## 2. Setup-status — versioned (implemented for v1)

Local marker `assoc_setup_version`:

- `0` / absent = incomplete
- `1` = Wizard v1 completed

Core does not depend on an external server for setup status. Future wizard revisions may increment the version; semantics for upgrades beyond v1 remain for a later ADR.

---

## 3. First activation (implemented)

- A user with `manage_association` may be redirected once after genuine fresh activation.
- No redirect loops; unauthorized users do not consume the pending marker.
- No redirect for WP-CLI, AJAX, cron, REST, admin-post, or network admin.
- No redirect if migration failed.
- Never force the wizard after setup is complete for the current setup version.

---

## 4–6. Help system, guide areas, integrations posture

Unchanged proposals. See earlier sections in git history / product review notes. **Not implemented.**

---

## 7–22. Registered-install Communications

Unchanged proposals. Pull/poll bulletins, voluntary registration, signing, and telemetry remain **proposal only**. Explicit non-goals (mandatory registration, push servers, executable remote code, member payloads, secret telemetry) still apply.

---

## Relation to current implementation

| Existing | This document |
|---|---|
| Association Settings (roles / meeting types) | Canonical structure editor; wizard reuses it |
| First-run Setup Wizard v1 | **Implemented** (ADR-0023) |
| Overview empty-state setup links | Complement wizard after completion |
| WordPress plugin update checks | Separate from registered-install bulletins |
| Handbook / Help & Guides / Message center | **Not implemented** (still proposal) |

---

## Open questions (Help / bulletins only)

Q38–Q39 are resolved by ADR-0023. Remaining open items for Help/Guides/bulletins are Q40–Q45 in `OPEN_QUESTIONS.md`.
