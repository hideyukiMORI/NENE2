# Roadmap

This roadmap keeps NENE2 focused on a small, modern, AI-readable PHP framework foundation and delivery starter.

## North Star

NENE2 should let a developer clone one repository and quickly build:

- JSON APIs with clear contracts
- thin server HTML when needed
- React frontend starters without backend lock-in, while allowing applications to choose another frontend stack
- testable use cases and adapters
- OpenAPI and MCP integrations through documented boundaries

The first practical delivery target is documented in `docs/integrations/llm-delivery-starter.md`: NENE2 should help its maintainer start LLM-assisted client work with a working API, clear OpenAPI handoff, and safe MCP integration path.

## Phase 0: Project Foundation

Goal: make contribution, AI operation, and technical direction unambiguous before runtime code grows.

- README and contribution guide
- Issue-driven workflow
- local roadmap, milestone, and TODO board
- coding standards
- self-review checklist policy
- language and package selection guardrails
- Cursor rules
- OpenAPI / MCP direction documents
- standard repository layout
- ADR operation policy

Tracked by `docs/milestones/2026-05-nene2-foundation.md`.

## Phase 1: Framework Skeleton

Goal: create the smallest useful runtime structure.

- HTTP request/response abstraction
- PSR-7 / PSR-15 / PSR-17 based HTTP runtime policy
- route dispatch foundation
- middleware pipeline foundation
- middleware security baseline policy
- logging and observability policy
- typed config loading and environment policy
- Composer package metadata
- PSR-11 dependency injection and explicit wiring policy
- RFC 9457 Problem Details error response policy
- layered request validation and readonly DTO policy
- minimal HTML response support through native PHP templates
- database migration layout and tool selection policy
- first PHPUnit test suite
- PHPDoc / TSDoc and quality tools policy
- release, versioning, and CI policy

## Phase 2: API Contract Foundation

Goal: make API behavior explicit and client-friendly.

- OpenAPI document location and generation policy
- request and response schema conventions
- contract-first request validation tests
- shared Problem Details error schemas
- contract tests
- examples for success and failure responses

## Phase 2.5: Database Adapter Foundation

Goal: support database-backed applications without making framework core database-dependent.

- choose migration runner, with Phinx as the first candidate
- define database config and environment variables
- add repository / adapter boundaries
- define test database strategy
- document migration and seed commands

## Phase 3: Frontend Integration

Goal: make the React + TypeScript starter easy to adopt without making NENE2 a frontend framework or blocking other frontend stacks.

- React + TypeScript `frontend/` starter policy
- npm, active Node.js LTS, and lockfile policy
- Vite-oriented integration notes
- API client generation or typed fetch wrapper policy
- SPA shell and static asset serving guidance

## Phase 4: MCP and AI Tooling

Goal: let AI tools inspect and operate the app through safe, documented contracts.

- LLM-assisted delivery starter direction
- MCP server integration guidance
- API-key or token boundary policy
- tool definitions derived from OpenAPI where practical
- safe local development commands
- request id and structured log context for AI-assisted debugging

## Phase 5: Quality and Release

Goal: keep the framework small while making changes safe.

- static analysis
- formatter and linter configuration
- PHP-CS-Fixer, ESLint, TypeScript, and Prettier check commands
- dependency update automation such as Dependabot or Renovate
- logging, metrics, and error tracking adapter policy
- GitHub Actions, SemVer, tags, and release checklist
- mutation or architecture tests where useful
- semantic versioning policy
- release checklist

Tracked by `docs/milestones/2026-05-v0.1.0-release-readiness.md` for the first foundation release.

## Phase 6: LLM Delivery Starter

Goal: make the released foundation useful for real LLM-assisted client delivery.

- local MCP server that clients can connect to
- read-only MCP tools aligned with OpenAPI and Problem Details behavior
- API-key authentication middleware for machine clients
- endpoint scaffold workflow that keeps PHP code, OpenAPI, and tests aligned
- real service database verification through Docker Compose
- small `v0.1.x` patch releases when delivery-starter increments are worth tagging

Tracked by `docs/milestones/2026-05-llm-delivery-starter.md`.

## Phase 7: Client Delivery Hardening

Goal: make the delivery-starter foundation easier to reuse for small client-style API projects.

- README entry points for setup, verification, and current capabilities
- new project start guidance for adapting the foundation
- local MCP client configuration examples
- protected machine-client API smoke workflows
- `v0.1.x` checkpoint release decisions after useful delivery-starter increments

Tracked by `docs/milestones/2026-05-client-delivery-hardening.md`.

## Phase 8: First LLM Field Trial

Goal: prove the delivery-starter workflow in a real or realistic client-style project.

- start from the `v0.1.1` checkpoint release
- add a small endpoint using the documented scaffold workflow
- connect a local LLM/MCP client through the documented stdio configuration
- record at least one MCP tool call through the API boundary
- turn observed friction into focused follow-up Issues

Tracked by `docs/milestones/2026-05-field-trial.md`.

## Non-Goals

- Recreating Laravel or Symfony.
- Hiding application behavior behind magic conventions.
- Coupling the framework to one frontend library.
- Making a full template engine a default dependency.
- Treating AI integration as direct database or filesystem access.
