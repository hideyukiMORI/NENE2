# Agent / AI Guide

This file is the entry point for AI agents and automation working on NENE2.

## Read First

- Human and AI collaboration: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Commit messages: `docs/development/commit-conventions.md`
- AI tool policy: `docs/integrations/ai-tools.md`
- Roadmap: `docs/roadmap.md`
- Current work / handoff: private `nene-origin/internal-docs/nene2/todo/current.md` (operational logs — `docs/todo`, `docs/daily`, field-trials, ft-registry — moved to the private mirror in P3, 2026-07-18; the public repo keeps only Diátaxis docs + ADR/CHANGELOG)
- Field trial registry: private `nene-origin/internal-docs/nene2/field-trials/ft-registry.md`

## Operating Rules

- Work from GitHub Issues. If an implementation or documentation change has no Issue, create one before editing.
- Do not commit directly to `main`. Use branches named like `type/issue-number-summary`.
- Keep local milestones and the private handoff (`nene-origin/internal-docs/nene2/todo/current.md`) aligned with Issues and PRs.
- Keep changes focused. Do not mix architecture migration, feature work, tool setup, and cosmetic cleanup in one PR.
- Do not commit secrets, credentials, local `.env` files, or generated build outputs.
- Prefer explicit, typed, testable code over hidden framework behavior.
- When docs and Cursor rules conflict, update the docs first and keep `.cursor/rules/` as a concise summary.

## Project Direction

NENE2 should stay small, modern, and AI-readable:

- JSON APIs first, with OpenAPI as the contract.
- Minimal server HTML, with an optional React starter and a replaceable frontend stack.
- MCP and AI integrations go through documented API boundaries.
- Test strategy is part of design, not an afterthought.
