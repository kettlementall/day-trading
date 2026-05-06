# Session Notes

## Purpose

This file is the handoff point for continuing work if the chat session is interrupted or a new session starts.

When resuming, read this file first, then inspect the current git/worktree state before making changes.

## Current Context

- Project path: `/home/wells/day-trading`
- User wants future sessions to be resumable.
- Keep this file updated when meaningful project decisions, active tasks, blockers, or implementation status change.

## Standing Project Memory

Documentation sync is required for this project:

- Update `SPEC.md` when a change affects specifications, business rules, schedules, formulas, AI selection or monitoring behavior, data models, API contracts, data flows, permissions, frontend-visible workflows, or backend/frontend architecture.
- Update `README.md` when a change affects setup, usage, deployment, commands, environment variables, or developer/operator instructions.
- If no documentation update is needed, mention that explicitly in the final response.

## Active Work

- Updated `stock:update-overnight-results` so it can also repair existing overnight `candidate_results` rows with missing overnight fields.
- Documentation sync for this behavior belongs in `SPEC.md` and `README.md`.

## Resume Checklist

1. Read `SESSION.md`.
2. Check `git status --short`.
3. Review any task-specific files before editing.
4. Preserve unrelated user changes.
5. Update documentation when required by the standing project memory.
