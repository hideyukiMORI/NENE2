# Roadmap

This roadmap keeps NENE2 focused on a small, modern, AI-readable PHP framework foundation.

## North Star

NENE2 should let a developer clone one repository and quickly build:

- JSON APIs with clear contracts
- thin server HTML when needed
- React or Vue frontends without backend lock-in
- testable use cases and adapters
- OpenAPI and MCP integrations through documented boundaries

## Phase 0: Project Foundation

Goal: make contribution, AI operation, and technical direction unambiguous before runtime code grows.

- README and contribution guide
- Issue-driven workflow
- local roadmap, milestone, and TODO board
- coding standards
- Cursor rules
- OpenAPI / MCP direction documents

Tracked by `docs/milestones/2026-05-nene2-foundation.md`.

## Phase 1: Framework Skeleton

Goal: create the smallest useful runtime structure.

- HTTP request/response abstraction
- route dispatch foundation
- config loading
- dependency injection container or factory policy
- error handling and JSON response shape
- minimal HTML response support
- first PHPUnit test suite

## Phase 2: API Contract Foundation

Goal: make API behavior explicit and client-friendly.

- OpenAPI document location and generation policy
- request and response schema conventions
- error schema
- contract tests
- examples for success and failure responses

## Phase 3: Frontend Integration

Goal: make React or Vue easy to adopt without making NENE2 a frontend framework.

- `frontend/` starter policy
- Vite-oriented integration notes
- API client generation or typed fetch wrapper policy
- SPA shell and static asset serving guidance

## Phase 4: MCP and AI Tooling

Goal: let AI tools inspect and operate the app through safe, documented contracts.

- MCP server integration guidance
- API-key or token boundary policy
- tool definitions derived from OpenAPI where practical
- safe local development commands

## Phase 5: Quality and Release

Goal: keep the framework small while making changes safe.

- static analysis
- formatter and linter configuration
- mutation or architecture tests where useful
- semantic versioning policy
- release checklist

## Non-Goals

- Recreating Laravel or Symfony.
- Hiding application behavior behind magic conventions.
- Coupling the framework to one frontend library.
- Treating AI integration as direct database or filesystem access.
