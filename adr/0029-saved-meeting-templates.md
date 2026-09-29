# ADR-0029: Saved meeting templates

**Status:** Accepted

**Date:** 2026-09-29

## Context

An association needs to keep its own meeting agendas and use them again. The plugin already stores that as a meeting template: a name, a meeting type, and an ordered list of headings. Creating a meeting copies those headings onto the meeting.

The association also needs a few starting points, including a blank one, without those starting points becoming templates that someone edits in place.

## Decision

- The plugin ships four starting points: annual meeting, board meeting, association meeting, and an empty template. They are code, not rows in `assoc_meeting_template`.
- Choosing a starting point opens a short guide. The association checks which of the original items to keep. The next page adds headings, renames the template, and changes the order. That page is the association-owned meeting template. It can be opened later and used for more than one meeting. The annual-meeting starting point lists the usual annual-meeting items.
- The association meeting starting point is copied onto the built-in member-meeting type. The empty starting point uses the meeting type the association chooses.
- A meeting stores its own copy of the saved template's headings. A later edit to the saved template does not change meetings already created. A later edit to a starting point does not change templates already saved.
- No second template table is added. Schema stays 17. Plugin version stays 0.1.0.

## Consequences

- The annual-meeting list is the original checklist. Unchecked items are left out of the saved template. The association can still add, rename, remove, and reorder headings afterwards.
- A saved template still belongs to one meeting type, so it can be copied only onto a meeting of that type.
