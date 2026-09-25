# Setup, Help, Guides and Registered-install Communications

**Status:** product direction / proposal — **not implemented**, not LOCKED  
**Context:** owner product brief captured for foundation review (2026-09-25)  
**Implementation:** requires ADRs (and owner decisions) for open points listed here and in `OPEN_QUESTIONS.md`. Do not start coding this area as if approved.

This document is the canonical home for the proposed product communications and onboarding surface. It does not replace `docs/10_ADMIN_UX.md` (current admin navigation and implemented screens) and it is **not** the same thing as the existing **association setup v1** Settings screen (board roles / meeting types). That settings work remains as documented in `PROJECT_STATE.md` and `docs/10_ADMIN_UX.md`.

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

## New proposals (need decisions before coding)

Everything below is **PROPOSAL** unless an ADR later locks it.

1. Four product surfaces treated as features, not post-hoc docs: First-run Setup Wizard, contextual help, complete handbook/guides, message center for registered installations.
2. Versioned local setup status (e.g. `assoc_setup_version`), not a mere boolean.
3. Optional first-activation redirect into the wizard for capable admins only.
4. Help menu IA under Association (Swedish/English titles TBD).
5. Optional registered-install communications: pull/poll bulletins, voluntary registration, optional consented minimal install telemetry.
6. Cryptographic signing of bulletins (security design TBD).
7. Whether Messages lives under Help or as top-level Association nav.

---

## Syfte

Föreningsplugin ska inte bara vara funktionellt. Det ska hjälpa en förening från första installation till dagligt arbete.

Användaren ska inte behöva förstå pluginets interna informationsarkitektur för att komma igång.

Produkten ska därför innehålla fyra sammanhängande delar:

1. **First-run Setup Wizard**
2. **Kontextuell hjälp**
3. **Komplett handbok / guider**
4. **Meddelandecenter för registrerade installationer**

Dessa ska betraktas som **produktfunktioner**, inte som dokumentation som skrivs i efterhand.

---

## 1. First-run Setup Wizard

After first install/activation, an admin should not only meet the full Association menu without context.

**Proposed flow:**

Install → Welcome → Setup Wizard → Configured → Overview → next steps (members, board, first meeting, handbook)

**Hard constraint for any future implementation:** the wizard must reuse the same application services and data as ordinary admin pages. No separate wizard database and no parallel settings model.

### Proposed wizard steps

| Step | Intent |
|---|---|
| Welcome | Orient; what the plugin is for |
| Föreningen | Association profile basics |
| Medlemskap | Membership types; Person vs Membership (and vs `wp_user` where relevant) |
| Styrelsen | Roles; not required to fill the entire board in the wizard |
| Möten | Meeting types / how meetings work in the product |
| Protokoll och dokument | Minutes and documents at a product level |
| Integritet | Privacy posture in the product — **not legal advice** |
| Klart | Completion; point to Overview and handbook next steps |

Exact step list, copy, and which fields are required remain OPEN pending ADR / UX design.

---

## 2. Setup-status — versioned

Proposed local marker, e.g. `assoc_setup_version = 1` (integer / versioned), **not** a mere boolean.

- Allows future wizard revisions without treating every install as “done forever” incorrectly.
- **Core must not depend on an external server** for setup status. Status is local to the WordPress installation.

Semantics of the version number (what “1” means, when it increments, migration of incomplete setups) are OPEN — see `OPEN_QUESTIONS.md`.

---

## 3. First activation

Proposed behavior:

- A capable admin **may** be redirected to the wizard after first activation.
- No redirect loops.
- No redirect for unauthorized users.
- Never force the wizard after setup is complete for the current setup version.

Exact capability gate and “first activation” detection remain OPEN.

---

## 4. Help system

Proposed:

- **Contextual help** per workspace (Members, Board, Meetings, …), discrete and non-intrusive.
- **Complete handbook** under Association → Help & Guides (Swedish title TBD, e.g. Hjälp och guider).
- No aggressive popups or nags.

Relation to WordPress Screen Options / Help tabs vs custom Association UI is OPEN.

---

## 5. Guide areas

Proposed handbook areas (detailed outlines to be authored later; substance is product guidance, not API docs):

| Area | Examples of coverage |
|---|---|
| Kom igång | Install, wizard, first Overview, first next steps |
| Medlemmar | Person, membership kinds, periods, accounts linkage |
| Styrelsen | Roles, assignments, history, replacement without destroying history |
| Möten | Prepare → meet → record → decide → finalize |
| Beslut / uppgifter | Cross-meeting follow-up vs meeting as source of truth |
| Dokument | Private files, visibility, minutes PDF / signed copy |
| Integritet | Export/erase posture, retention settings, what the product does *not* claim legally |
| Integrationer | Adapters, ownership, what not to dual-write |

Sub-page IA and authoring ownership are OPEN.

---

## 6. Integrations architecture (guide + product posture)

Proposed product posture for integrations (guides must teach this):

- Prefer **adapters** over rebuilding unrelated systems.
- Be explicit about **data ownership** and the master record.
- Avoid two-way sync without a clear master.
- Integration guides must answer ownership questions before suggesting a connector.

This aligns with locked “prefer integration over rebuilding” and “one source of truth” without locking a specific adapter catalog.

---

## 7–22. Registered-install Communications

Separate from WordPress plugin updates. This is a **message center** for product news, security notices, and similar communications to **voluntarily registered** installations.

### 7. Purpose

Product communications for registered installs — not a substitute for `update_plugins` / wordpress.org update checks.

### 8. Transport model

- **Pull / poll**, not inbound push into the site.
- Infrequent check (proposed order of magnitude: every 12 hours or daily).
- Prefer WP-Cron for scheduling.
- Cache results locally.
- **Fail-open for core:** if the bulletin service is unreachable, misconfigured, or refused, association administration continues normally.

### 9. Payload

- Declarative **JSON bulletins only**.
- **Never** executable code in bulletins.
- Strict validation before accept.
- Escaped render in admin UI (treat bulletin content as untrusted until validated/signed per future security design).

### 10. Targeting and identity

- Local targeting rules (e.g. by plugin version / locale) evaluated on the install.
- **Voluntary registration** only.
- Random `installation_id` (opaque install identifier).
- **No member / person data** in registration or poll requests.
- Minimal install telemetry **only with consent** — and only if a future decision allows a narrow, documented payload that still satisfies locked “no secret telemetry”.

### 11. Message types and UI

- Typed messages (product news, security, deprecation, etc. — exact enum OPEN).
- Admin notice reserved for **critical** items; ordinary messages stay in the message center.
- Outdated-version detection is **separate** from bulletin content (local version comparison vs known current — exact mechanism OPEN).

### 12. Trust and signing

- Cryptographic signing of bulletins is **TBD** as a separate security design / ADR.
- If signing is adopted: **fail-closed** for invalid or unsigned bulletins (do not display them as trusted).
- Unsigned/optional mode vs mandatory signed mode is OPEN.

### 13. Privacy and unregister

- Clear privacy explanation for what registration sends (and what it never sends).
- Clear path to unregister / stop polling.
- Registration must never be required for core features, guides, or wizard completion.

### 14. Guides linkage

Bulletins may link to **version-matched** handbook pages where practical, so advice matches the installed plugin version.

### 15. Product principle lifecycle

This area must preserve the lifecycle of locked principles: useful offline base product, optional central communications, **no mandatory central SaaS**.

### Explicit non-goals for this proposal

- Mandatory registration
- Push servers that require inbound connectivity to the association site
- Executable remote code or remote configuration that changes domain data
- Sending member, person, or membership payloads
- Framing optional poll as required “telemetry product analytics”

---

## Relation to current implementation

| Existing | This proposal |
|---|---|
| Association Settings (roles / meeting types) — setup v1 | Different: structural settings, not first-run wizard |
| Overview empty-state setup links | May complement wizard; not a substitute for the proposed wizard |
| WordPress plugin update checks | Separate from registered-install bulletins |
| Handbook / Help & Guides / Message center | **Not implemented** |

---

## Open questions before coding

Durable items are also listed in `OPEN_QUESTIONS.md`. Do not invent answers here.

1. Bulletin signing model (keys, rotation, fail-closed rules).
2. Telemetry consent UX (what is asked, defaults, how consent is stored and revoked).
3. Registration service ownership and hosting (who runs it; how open-source installs point at it; offline default).
4. `assoc_setup_version` semantics (increments, incomplete setups, upgrades).
5. Help menu information architecture (Swedish/English labels; Screen Help vs custom pages).
6. Whether Messages sits under Help or as top-level Association navigation.
7. Capability gates for wizard redirect, handbook, and message center.
8. Exact bulletin JSON schema and allowed HTML/Markdown subset for rendering.
9. Poll interval, backoff, and interaction with disabled WP-Cron.
10. How outdated-version detection relates to wordpress.org / manual updates without duplicating or fighting core update UI.

---

## Decision and ADR expectation

- Do **not** mark this document LOCKED.
- Before implementation: record ADRs for transport/trust (bulletins), registration/privacy, and setup-version semantics at minimum.
- Until then: treat this file as owner direction for product design review.
