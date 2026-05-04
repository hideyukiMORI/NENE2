# Agent / AI Guide

This file is the entry point for AI agents and automation working on NENE2.

## Read First

- Human and AI collaboration: `docs/CONTRIBUTING.md`
- Workflow: `docs/workflow.md`
- Coding standards: `docs/development/coding-standards.md`
- Commit messages: `docs/development/commit-conventions.md`
- AI tool policy: `docs/integrations/ai-tools.md`
- Roadmap: `docs/roadmap.md`
- Current work: `docs/todo/current.md`

## Operating Rules

- Work from GitHub Issues. If an implementation or documentation change has no Issue, create one before editing.
- Do not commit directly to `main`. Use branches named like `type/issue-number-summary`.
- Keep local milestones and `docs/todo/current.md` aligned with Issues and PRs.
- Keep changes focused. Do not mix architecture migration, feature work, tool setup, and cosmetic cleanup in one PR.
- Do not commit secrets, credentials, local `.env` files, or generated build outputs.
- Prefer explicit, typed, testable code over hidden framework behavior.
- When docs and Cursor rules conflict, update the docs first and keep `.cursor/rules/` as a concise summary.

## Project Direction

NENE2 should stay small, modern, and AI-readable:

- JSON APIs first, with OpenAPI as the contract.
- Minimal server HTML, with React/Vue integration kept optional and replaceable.
- MCP and AI integrations go through documented API boundaries.
- Test strategy is part of design, not an afterthought.
