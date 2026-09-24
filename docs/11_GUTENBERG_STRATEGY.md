# Gutenberg strategy — draft requirements

## Goal

Public website output reads the same association data used by administration.

Avoid copied/manual duplicate content.

## Initial blocks

- Current board
- Latest board meeting
- Latest minutes
- Document archive
- Member count

## Likely rendering model

Evaluate dynamic/server-side rendering for data that changes independently of page content.

Reasons to consider dynamic blocks:
- board changes should update existing pages automatically
- latest meeting/minutes should remain current
- permissions/visibility may need server checks
- reduces duplicated serialized association data in post content

This is not yet a locked architecture decision.

## Block concerns

For each block define:
- attributes/configuration
- public/private visibility
- empty state
- caching behavior
- escaping
- accessibility
- theme compatibility
- translation
- preview/editor behavior

## Security

Never leak:
- private member contact details
- non-public minutes
- board-only documents
- protected attachment URLs

A block's editor preview must not accidentally make protected data available to unauthorized REST users.
