---
title: I built a tiny PHP framework for AI-readable business APIs
published: false
description: Introducing NENE2, an API-first PHP micro-framework built around OpenAPI, explicit architecture, and MCP-ready boundaries.
tags: php, opensource, api, ai
---

I have been building a small PHP framework called **NENE2**.

It is not a Laravel replacement.
It is not a full-stack framework.
It is not trying to hide everything behind magic.

NENE2 is a tiny, API-first PHP foundation for building **AI-readable business APIs**: small services with explicit HTTP boundaries, OpenAPI contracts, Problem Details errors, and optional MCP tool catalogs for AI agents.

Repository:

https://github.com/hideyukiMORI/NENE2

## Why build another PHP framework?

Most business software does not start as a beautiful greenfield platform.

It starts as a request like:

- "Can we expose this workflow as an API?"
- "Can an internal tool call this safely?"
- "Can an AI agent look up the status without touching the database?"
- "Can we keep this small enough that a team can actually understand it?"

Large frameworks are great when you need their ecosystem.

But for small business APIs, I often want something quieter:

- explicit routes
- small handlers
- use cases that do not know about HTTP
- repository interfaces
- OpenAPI as the public contract
- predictable error responses
- tests that prove the behavior
- a clean boundary for AI / MCP tools

That is the design space where NENE2 lives.

## The core idea: API first, AI readable

NENE2 assumes that the API is the primary surface.

Server-rendered HTML can exist, and a React frontend starter can exist, but the backend should not become frontend build glue. The stable contract is the HTTP API.

The framework is organized around:

- **PSR-7 / PSR-15 / PSR-17** HTTP runtime direction
- explicit routing and middleware
- **OpenAPI 3.1** contract documentation
- **RFC 9457 Problem Details** for errors
- typed configuration objects
- PSR-11 dependency injection
- PDO database adapters and transaction boundaries
- optional React + TypeScript frontend starter
- local MCP server support behind the same API boundary

The goal is not to make the code clever.

The goal is to make the code easy to inspect by a human developer, a reviewer, or an AI coding agent.

## A boring architecture on purpose

The reference domain example follows a simple flow:

```text
HTTP Handler
  -> Use Case
  -> Repository Interface
  -> PDO Adapter
```

For example, the Note CRUD reference implementation includes:

- route + handler classes
- use case classes
- readonly input/output DTOs
- repository interface
- PDO repository
- exception-to-Problem-Details mapping
- OpenAPI paths and schemas
- unit, HTTP, and PDO integration tests

This is intentionally boring.

I want application behavior to be visible in the file tree instead of being implied by framework convention.

## OpenAPI is the handoff document

For NENE2, OpenAPI is not an afterthought.

The repository ships an OpenAPI 3.1 document, Swagger UI, and runtime contract tests for example endpoints.

Locally, after starting the app:

```bash
docker compose up -d app
```

Useful URLs include:

```text
http://localhost:8200/health
http://localhost:8200/examples/ping
http://localhost:8200/openapi.php
http://localhost:8200/docs/
```

This matters because the OpenAPI file is also the bridge to other tools:

- generated clients
- contract tests
- documentation
- MCP tool catalogs
- AI-assisted development workflows

The API contract is the shared language.

## MCP should not mean "let AI touch the database"

One of the ideas behind NENE2 is that AI agents should operate through documented API boundaries.

I do not want an agent to directly query or mutate production tables.

Instead:

```text
AI Agent
  -> MCP Tool
  -> documented HTTP API
  -> application use case
  -> repository / transaction boundary
```

NENE2 includes local MCP server support and guidance for mapping tools to OpenAPI-backed operations.

Read tools can inspect state.
Write tools require explicit authentication.
The application still owns validation, authorization, logging, and error handling.

That boundary is the important part.

## Field-tested through real apps

NENE2 is not only a framework repository.

I have been using it as the foundation for a family of self-hosted business OSS projects, including:

- **NeNe Invoice** — quote and invoice management for Japan qualified invoices
- **NeNe Vault** — received-document archive for electronic record retention
- **NeNe Records** — typed headless CMS / flexible entity platform
- **NeNe Deal** — lightweight B2B deal pipeline
- **NeNe Contact** — embeddable contact forms
- **nene-mcp** — stdio MCP bridge for OpenAPI-backed HTTP APIs
- **nene2-python** — Python reference implementation of the same philosophy
- **nene2-js** / **nene2-node** — TypeScript ecosystem pieces

Some of these products are Japan-specific.
That is fine.

The point is that the framework is tested against real business shapes:

- authentication
- multi-tenancy
- OpenAPI
- admin UIs
- Docker local stacks
- database migrations
- exports
- audit logs
- MCP catalogs
- AI-readable documentation

Framework design gets better when it is forced through real applications.

## What NENE2 is not

NENE2 is deliberately small.

It is not:

- a Laravel or Symfony replacement
- a no-code platform
- an ORM-first framework
- a CMS
- a frontend framework
- an AI agent runtime

It is a small foundation for teams or solo developers who want explicit PHP APIs and documented handoff points.

## Quick start

Clone the repository:

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose up -d app
```

Or install it as a Composer dependency:

```bash
composer require hideyukimori/nene2
```

NENE2 targets PHP 8.4+ and uses Docker as the standard development runtime.

## Why "AI-readable" matters

AI-assisted development is not only about generating code.

It is also about whether the system is readable enough for an agent to help safely:

- Can it find the route?
- Can it understand the request and response shape?
- Can it see the use case?
- Can it run the focused tests?
- Can it avoid bypassing validation?
- Can it call a documented tool instead of guessing a database query?

NENE2 tries to make those answers obvious.

Not by adding more magic.

By removing ambiguity.

## Links

- NENE2: https://github.com/hideyukiMORI/NENE2
- Packagist: https://packagist.org/packages/hideyukimori/nene2
- nene2-python: https://github.com/hideyukiMORI/nene2-python
- nene2-js: https://github.com/hideyukiMORI/nene2-js
- nene-mcp: https://github.com/hideyukiMORI/nene-mcp
- GitHub profile: https://github.com/hideyukiMORI

I am still refining the framework and the surrounding NeNe OSS series.

If you are interested in API-first PHP, OpenAPI, MCP, or AI-readable business software, feedback is very welcome.
