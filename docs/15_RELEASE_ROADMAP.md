# Provisional roadmap

Version numbers are illustrative and not locked. This file does not lock a phase, and it does not accept a document that still says **proposed**.

## Original plan and current repository

The phase list below is the original sequence. It is not a completion report.

Phase A said to write the design baseline and not to implement features before that baseline was accepted. That sentence records the original gate. It is not a description of the tree now.

Design files for that baseline exist. These still say **proposed**, and this roadmap does not change that status:

- `docs/DOMAIN_MODEL.md`
- `docs/MEETING_STATE_MACHINE.md`
- `docs/CAPABILITY_MATRIX.md`
- `docs/PLUGIN_ARCHITECTURE.md`
- `docs/TESTING_PLAN.md`

Locked, provisional, and open decisions stay in `DECISIONS.md`.

The repository already contains plugin code. `plugin/foreningsplugin.php` bootstraps `Plugin::register`. `WordpressMigrations` registers schema versions 1 through 17. PHPUnit, the Docker lab, and `.github/workflows/tests.yml` are present. Those facts do not mark a phase finished.

Mina sidor is not a finished feature. See `docs/MEMBER_AREA.md`. The deferred track "member portal" stays deferred.

## Phase A — Design baseline

This phase is the original plan: write the design documents below before feature code.

Deliver:
- glossary
- domain model
- meeting state machine
- privacy lifecycle
- capability matrix
- data architecture ADRs
- plugin architecture
- testing baseline

The original gate was: no feature implementation before this baseline is accepted. See **Original plan and current repository**. Documents in this list that still say **proposed** are not locked here.

## Phase B — Skeleton and infrastructure

- plugin bootstrap
- namespaces/autoloading
- activation/install
- migration framework
- test harness
- CI/lint/static analysis
- capability bootstrap

## Phase C — People and membership

- Person
- Membership
- import/export basics
- privacy lifecycle

## Phase D — Board

- roles
- assignments
- history
- current board query
- first public block

## Phase E — Meetings core

- types/templates
- meetings
- participants
- agenda
- notes
- decisions
- action items
- workflow

## Phase F — Minutes

- draft composition
- editing/review
- revision/finalization
- immutability/correction model

## Phase G — Print/PDF/signing archive

- print layout
- PDF generation
- signature section
- signed-copy upload
- document links/access

## Phase H — Documents and public blocks

- document archive
- visibility
- remaining MVP blocks

## Phase I — Hardening

- security review
- privacy review
- migration upgrade tests
- accessibility
- i18n Swedish/English
- performance
- WordPress.org packaging readiness

## Deferred tracks

- membership fees/payments
- activities/calendar
- recipient selection/email integrations
- member portal
- e-signature providers
- sections or departments, activity reports, and internal section budgets (`docs/19_SECTIONS.md`, FUTURE / NOT IMPLEMENTED)
