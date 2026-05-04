# Roadmap

This roadmap keeps NENE2 focused on a small, modern, AI-readable PHP framework foundation.

## North Star

NENE2 should let a developer clone one repository and quickly build:

- JSON APIs with clear contracts
- thin server HTML when needed
- React frontend starters without backend lock-in, while allowing applications to choose another frontend stack
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
- standard repository layout

Tracked by `docs/milestones/2026-05-nene2-foundation.md`.

## Phase 1: Framework Skeleton

Goal: create the smallest useful runtime structure.

- HTTP request/response abstraction
- PSR-7 / PSR-15 / PSR-17 based HTTP runtime policy
- route dispatch foundation
- middleware pipeline foundation
- middleware security baseline policy
- typed config loading and environment policy
- Composer package metadata
- PSR-11 dependency injection and explicit wiring policy
- RFC 9457 Problem Details error response policy
- minimal HTML response support through native PHP templates
- database migration layout and tool selection policy
- first PHPUnit test suite
- PHPDoc / TSDoc and quality tools policy

## Phase 2: API Contract Foundation

Goal: make API behavior explicit and client-friendly.

- OpenAPI document location and generation policy
- request and response schema conventions
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
- PHP-CS-Fixer, ESLint, TypeScript, and Prettier check commands
- mutation or architecture tests where useful
- semantic versioning policy
- release checklist

## Non-Goals

- Recreating Laravel or Symfony.
- Hiding application behavior behind magic conventions.
- Coupling the framework to one frontend library.
- Making a full template engine a default dependency.
- Treating AI integration as direct database or filesystem access.
