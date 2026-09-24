# ADR-0020: Versioned member CSV

**Status:** Accepted  
**Date:** 2026-09-24

## Context

ADR-0011 describes one row per period. Family participants and organizations do not fit that file if every row must have a unique number.

## Decision

The treasurer export keeps the existing columns and does not contain personal identity numbers. A number that already exists is skipped, so a repeated import does not rewrite history.

A second file starts with `# foreningsplugin-members 2` and has `organization`, `membership`, `period` and `participant` rows. It can add a later period to an existing number. It also excludes personal identity numbers. A protected identity export is not provided until the owner decides the policy.

Cells that start with `=`, `+`, `-` or `@` are prefixed so a spreadsheet does not treat them as formulas.
