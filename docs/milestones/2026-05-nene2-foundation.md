# NENE2 Foundation

## Period

May 2026

## Goal

Create the project foundation for NENE2 before adding runtime framework code. The foundation should make the project easy to understand, easy to contribute to, and easy for AI agents to navigate.

## Theme

NENE2 should be a compact modern PHP framework for fast API development, minimal HTML output, optional React/Vue integration, and AI-readable architecture.

## Scope

- README direction
- contribution guide
- Issue-driven workflow
- local roadmap / milestone / TODO board
- coding standards
- OpenAPI and MCP integration policy
- Cursor rules

## Completion Summary

This milestone grew from initial governance into the first coherent NENE2 foundation.

Completed foundation areas:

- project workflow, contribution, roadmap, TODO, Cursor rule, and ADR policies
- standard repository layout
- PHP 8.4 Composer tooling, PHPUnit, PHPStan, PHP-CS-Fixer, and Docker development runtime
- PSR-7 / PSR-15 / PSR-17 HTTP runtime foundation
- internal route dispatch, middleware pipeline, request id, security header, CORS, and request size boundaries
- RFC 9457 Problem Details policy and OpenAPI shared error schemas
- typed configuration loading with `.env.example`
- PSR-11 container foundation and runtime service provider wiring
- native PHP view rendering boundary
- OpenAPI contract validation and lightweight runtime contract tests
- React + TypeScript + Vite frontend starter and first `/health` integration path
- Phinx migration runner selection and typed database configuration
- PDO database connection, query executor, and transaction manager boundaries
- SQLite-first database adapter test strategy
- backend and frontend GitHub Actions workflows
- Dependabot configuration for Composer, GitHub Actions, and npm
- MCP tool safety policy, read-only catalog, local server guidance, and authentication boundary policy
- branch protection readiness and first `v0.1.0` release preparation notes

## Release Readiness

The first foundation release is prepared but not tagged.

Release preparation lives in:

- `docs/development/release-v0.1.0-prep.md`
- `docs/development/release-checklist.md`
- `docs/development/branch-protection-readiness.md`

The next milestone should focus on post-foundation release readiness and early framework hardening before broadening application features.

## Acceptance Criteria

- A new contributor can understand the project direction from `README.md`.
- A human or AI agent can find the correct workflow from `AGENTS.md` and `docs/CONTRIBUTING.md`.
- Branch, commit, PR, and merge rules are documented in `docs/workflow.md`.
- Coding rules favor modern PHP, decoupling, testability, and API contracts.
- Local project memory exists in `docs/roadmap.md`, `docs/milestones/`, and `docs/todo/current.md`.
- Cursor rules summarize the docs without becoming the source of truth.
- The first runtime, contract, frontend, database, MCP, CI, and release-prep boundaries are documented and verified by CI.

## Related Work

- GitHub Issue: `#1`
- Current completed work is tracked in `docs/todo/current.md`.
