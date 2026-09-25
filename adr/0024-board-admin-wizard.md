# ADR-0024: Board admin wizard

**Status:** Accepted  
**Date:** 2026-09-25

## Context

Board administration was a single long page: current board, upcoming changes, full history, and every replace/add/end/cancel form at once. That made the primary task (“replace treasurer without destroying history”) harder than necessary and mixed browsing history with mutations.

Setup wizard v1 already established server-rendered step navigation, sibling forms (no nesting), and capability-gated admin HTML.

## Decision

- Replace the long Board page with a guided wizard: Overview → task → role → person/dates → confirm → done.
- Two presentation modes only: **Get started** (no current/upcoming seats) and **Change the board** (ongoing). Mode does not change BoardService rules.
- One task per run: replace role, add holder, end assignment, or cancel scheduled change. Unavailable tasks are omitted.
- History is a separate view (`assoc_view=history`), not inline on overview.
- Role catalog remains Association Settings. The wizard only creates/changes assignments.
- Domain mutations stay in `BoardService` via existing admin-post actions and nonces (`manage_board`). Wizard orchestration lives in `Application/Board/BoardWizard*` and is request-scoped (query args), not setup options.
- Schema stays at 16. Plugin version stays 0.1.0. No SPA, drag-drop, or mass-import in v1.

## Consequences

- Officers change one board fact at a time with an explicit confirm step.
- Labs and docs describe wizard navigation instead of overview mutation forms.
- Swedish strings are required for new wizard copy; existing board notices and BoardService messages stay.
