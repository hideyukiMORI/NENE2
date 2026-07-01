---
title: I am building self-hosted business tools for small teams in Japan
published: true
description: A look at the NeNe OSS series: API-first, self-hosted business tools built on NENE2 for teams operating in Japan.
tags: opensource, php, api, ai
---

I have been building a small family of open source business tools called the **NeNe** series.

The idea is simple:

**small teams should be able to run useful business software on their own infrastructure, with clear APIs and readable boundaries.**

Most of the tools are still being refined, but the direction is already visible:

- self-hosted
- API-first
- bilingual where Japan operations need it
- OpenAPI documented
- MCP-ready for AI agents
- small enough for one developer or a small team to understand

The foundation is **NENE2**, my PHP 8.4 API-first micro-framework.

Repository:

https://github.com/hideyukiMORI/NENE2

GitHub profile:

https://github.com/hideyukiMORI

## Why Japan-specific business tools?

Many teams operating in Japan have a mixed workflow.

The technical team may be comfortable with English.

The business workflow is still deeply local:

- qualified invoices
- received-document retention
- payment reconciliation
- Japanese bank CSV formats
- bilingual admin screens
- small company operations

Generic tools can work.

But sometimes the local workflow leaks through the cracks.

That is why the NeNe series is not just “another CRUD app collection”.

It is an attempt to build small, self-hosted tools that understand specific business boundaries.

## The products

The series includes several focused tools.

### NeNe Invoice

Self-hosted quote and invoice management for Japan small businesses.

It focuses on qualified invoices, quotes, invoices, payments, PDF output, and admin UI workflows.

Repository:

https://github.com/hideyukiMORI/nene-invoice

Japanese hands-on article:

https://qiita.com/xioncc/items/7439c9832a6795b4d48e

### NeNe Vault

Received-document archive for PDFs and business documents.

It focuses on storing received invoices, receipts, and contracts with search, audit history, and retention-oriented workflows.

Repository:

https://github.com/hideyukiMORI/nene-vault

Japanese hands-on article:

https://qiita.com/xioncc/items/2f3726347e4504363534

### NeNe Deal

Lightweight B2B deal pipeline management.

It handles opportunities, stages, simple forecasts, and handoff to NeNe Invoice as draft client / quote data.

Repository:

https://github.com/hideyukiMORI/nene-deal

Japanese hands-on article:

https://qiita.com/xioncc/items/5f7f0e3687d77f07e00e

### NeNe Contact

Embeddable contact forms and an operator inbox.

It lets operators build forms, embed them with a script tag, receive submissions, manage statuses, and route notifications.

Repository:

https://github.com/hideyukiMORI/nene-contact

Japanese hands-on article:

https://qiita.com/xioncc/items/580750a119ffaa1641af

### NeNe Records

API-first typed CMS / flexible entity platform.

It lets users define entity types, typed fields, records, public pages, and OpenAPI/MCP-facing operations.

Repository:

https://github.com/hideyukiMORI/nene-records

## What ties them together?

The products are different, but they share the same direction.

### 1. API-first boundaries

The API is the contract.

Admin screens, public pages, integrations, and AI tools should go through documented HTTP APIs.

That makes behavior easier to test, review, and expose safely.

### 2. OpenAPI as a shared language

Each product aims to document its public API with OpenAPI.

That matters for human developers.

It also matters for AI agents.

If the API is explicit, a tool can map to the operation without guessing database structure.

### 3. MCP should call the app

I wrote about this in my previous article:

https://dev.to/hideyukimori/mcp-should-not-mean-letting-ai-touch-your-database-57p1

My rule of thumb is:

**MCP tools should wrap application capabilities, not bypass the application.**

That means:

```text
AI Agent
  -> MCP Tool
  -> documented HTTP API
  -> handler
  -> use case
  -> repository / transaction boundary
```

Not:

```text
AI Agent
  -> MCP Tool
  -> direct SQL query
  -> production database
```

### 4. Boring architecture on purpose

The stack is intentionally not magical.

Most products follow a shape like:

```text
Handler
  -> Use Case
  -> Repository
```

The frontend is a client.

The database stays behind repositories.

The MCP layer stays behind HTTP APIs.

This is boring.

That is a feature.

## Why PHP?

Because PHP still matters for small business software.

It is widely deployable.

It is easy to host.

It is familiar to many developers who work on business systems.

Modern PHP can be strict, typed, tested, and API-first.

NENE2 is my attempt to keep PHP small and explicit instead of recreating a large full-stack framework.

## What is NENE2?

NENE2 is the small framework underneath many of these tools.

It provides:

- PSR-style HTTP runtime
- explicit routing
- Problem Details error responses
- OpenAPI contracts
- database boundaries
- auth middleware
- optional frontend starter patterns
- local MCP server support

It is not trying to replace Laravel.

It is a small foundation for building business APIs that humans and AI agents can both understand.

First DEV article:

https://dev.to/hideyukimori/i-built-a-tiny-php-framework-for-ai-readable-business-apis-48eo

## This is still a work in progress

I do not want to overstate the maturity of the portfolio.

Some tools are close to formal release.

Some are still being refined.

Some deployment paths are Docker-first today, with shared-hosting or managed paths still evolving.

The goal of the articles I am publishing now is not to claim that every product is finished.

The goal is to make the architecture, direction, and working software visible.

## What I am looking for

I am especially interested in feedback from people who care about:

- self-hosted business software
- API-first PHP
- OpenAPI contracts
- MCP and AI agent boundaries
- small tools for real operational workflows
- software for teams operating in Japan

If you are building similar tools, or thinking about how AI agents should interact with business APIs, I would be happy to hear your perspective.

## Links

- GitHub profile: https://github.com/hideyukiMORI
- NENE2: https://github.com/hideyukiMORI/NENE2
- NeNe Invoice: https://github.com/hideyukiMORI/nene-invoice
- NeNe Vault: https://github.com/hideyukiMORI/nene-vault
- NeNe Deal: https://github.com/hideyukiMORI/nene-deal
- NeNe Contact: https://github.com/hideyukiMORI/nene-contact
- NeNe Records: https://github.com/hideyukiMORI/nene-records
- nene-mcp: https://github.com/hideyukiMORI/nene-mcp

I am still refining the NeNe series and NENE2.

But the shape is becoming clearer:

**small, self-hosted, API-first business tools with AI-readable boundaries.**
