# Original project brief — condensed source of truth

The project began as an idea for a membership register but expanded into a broader association system.

## Purpose

Build a modern, self-hosted WordPress association system for primarily small and medium-sized nonprofit associations.

Association data lives in one place. WordPress displays the correct information automatically where needed.

Examples:
- changing treasurer in admin should update an “Current board” block
- publishing a board meeting should allow “Latest board meeting” to update
- changing membership should update dashboard counts

## Primary target groups

- small nonprofit associations
- local heritage associations
- sports clubs
- boat clubs
- hunting teams/clubs
- motorcycle clubs
- homeowners/community associations
- cultural and theatre associations
- hobby associations
- local interest groups

The system must be understandable even if nobody on the board is technical.

## Core principles

- self-hosted
- no mandatory SaaS
- no mandatory external server
- no required subscription for core features
- no custom theme
- Gutenberg-compatible
- member != `wp_user`
- simplicity before feature creep
- GDPR/privacy from architecture onward
- internationalizable from the start
- Swedish and English first
- no trackers, ads or secret callbacks
- test critical operations

## Planned functional areas

- dashboard
- members
- board
- meetings
- decisions
- documents
- activities
- membership fees
- mail recipient selection
- settings/capabilities
- Gutenberg blocks

Not all areas belong in MVP.

## Important data-modelling principle

Do not assume everything should be a Custom Post Type.

Evaluate each domain type for:
- custom database table
- CPT
- taxonomy
- WordPress user
- WordPress media
- options/settings

Choose based on the data's characteristics, not coding convenience.
