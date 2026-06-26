---
title: MCP should not mean letting AI touch your database
published: false
description: A practical argument for using MCP tools as documented API boundaries, not production database shortcuts.
tags: ai, api, opensource, mcp
---

After publishing my first article about **NENE2**, a small PHP framework for AI-readable business APIs, one comment stood out:

> consistent JSON envelopes matter more than ever when agents are parsing responses.

I agree.

But predictable JSON is only one part of the story.

The bigger question is:

**What should an AI agent be allowed to touch?**

My answer is simple:

**An MCP tool should call your application boundary, not your production database.**

## MCP is not a license to bypass the app

MCP is useful because it gives AI tools a structured way to call capabilities.

But if we use it carelessly, it can become a very polished shortcut around the application:

```text
AI Agent
  -> MCP Tool
  -> direct SQL query
  -> production database
```

That may feel convenient during a demo.

It is also exactly the kind of thing I do not want in business software.

The database does not know the full application rules:

- validation
- authorization
- tenant isolation
- domain invariants
- audit logging
- rate limits
- Problem Details error behavior
- request IDs
- business workflow state

Your application knows those things.

So the agent should go through the application.

## The safer shape

The direction I use in NENE2 is:

```text
AI Agent
  -> MCP Tool
  -> documented HTTP API
  -> handler
  -> use case
  -> repository / transaction boundary
```

This is less magical.

That is the point.

If an AI agent asks for the status of a record, the tool should call the same read API that a normal client would call.

If an AI agent creates or updates something, the tool should call the same write API that already validates the request, checks credentials, logs the request, and returns a predictable error shape.

MCP should expose application capabilities.

It should not invent a second, hidden application.

## OpenAPI as the tool contract

In NENE2, OpenAPI is the public API contract.

That makes it a natural starting point for MCP tool definitions.

If the HTTP API says:

```text
GET /examples/notes/{id}
```

then an MCP tool can map to that operation:

```text
getExampleNoteById
```

The tool does not need to know where the note is stored.
It does not need SQL.
It does not need to load a `.env` file.

It needs:

- a tool name
- a JSON input schema
- an HTTP method and path
- the API base URL
- credentials when required
- a way to preserve errors and request metadata

The API remains the contract.

## Read tools first

I think most MCP integrations should start with read tools.

Read tools are easier to reason about:

- health checks
- list records
- get one record
- inspect history
- export a manifest
- summarize a dashboard

They still need authorization when the data is private, but they do not mutate state.

That makes them a good first milestone for teams learning how agents interact with their product.

In NENE2, MCP tools have safety levels such as:

- `read`
- `write`
- `admin`
- `destructive`

The first tools should usually be `read`.

Write tools need more design.

Admin and destructive tools need even more.

## Write tools need authentication and audit

Write tools are not forbidden.

But they should not be treated as a weekend shortcut.

A write tool needs the same kind of design you would expect from any sensitive API:

- authentication
- authorization
- request validation
- audit fields
- request ID propagation
- useful error responses
- tests for failure and permission boundaries
- confirmation behavior for destructive actions

In local NENE2 development, write tools can call documented write endpoints.

When `NENE2_LOCAL_JWT_SECRET` is configured, write tools require a valid Bearer JWT.

For production tools, the bar should be higher:

- owner
- allowed environments
- required scopes
- rate limits
- logging policy
- rollback or remediation behavior

Production MCP tools are product features.

They are not debugging shortcuts.

## Problem Details matter

When an MCP tool calls an HTTP API, it should preserve the API's error behavior.

For NENE2, that means **RFC 9457 Problem Details**.

If a request is invalid, the agent should see a structured error.

If authentication fails, the agent should see a structured 401.

If a domain object is not found, the agent should see a structured 404.

The tool should not collapse everything into:

```text
Something went wrong.
```

Agents need predictable failure shapes too.

Good error contracts are part of AI-readability.

## Credentials should stay out of the repository

This sounds obvious, but it matters.

MCP configuration often lives close to developer tooling.

That makes it tempting to commit values that should never be committed:

- API keys
- bearer tokens
- local secrets
- private paths
- database URLs

NENE2's guidance is:

- document environment variable names
- do not commit secret values
- do not return credentials in tool metadata
- do not let MCP tools read raw `.env` secrets
- do not print tokens in logs

A tool can say it requires a scope.

It should not expose the credential.

## Where nene-mcp fits

I also maintain **nene-mcp**, a small PHP stdio MCP bridge.

It can expose HTTP APIs as MCP tools using a committed `tools.json` catalog.

The shape is intentionally simple:

```text
MCP client
  -> stdio JSON-RPC
  -> nene-mcp
  -> HTTP API
```

It is not a customer-facing API gateway.

It is a sidecar for local development, staging, and small team workflows.

It works best when the application already has documented HTTP endpoints and a clear tool catalog.

That is the same idea again:

**MCP should call the app, not bypass it.**

## A concrete rule of thumb

When designing an MCP tool, I like to ask:

> Could a normal HTTP client safely do this through the documented API?

If yes, the MCP tool is probably a good wrapper.

If no, I ask another question:

> Why does this capability exist only for the agent?

Sometimes there is a good answer.

Often there is not.

If the capability is real business behavior, it probably deserves an application API, tests, documentation, and review.

## What this means for "AI-readable" APIs

An AI-readable API is not just an API with JSON.

It is an API where:

- the routes are discoverable
- the schemas are predictable
- the errors are structured
- the boundaries are explicit
- credentials are handled deliberately
- tools map to documented operations
- business rules are not hidden in a side channel

This is the design direction behind NENE2.

It is also why I prefer boring architecture:

```text
Handler
  -> Use Case
  -> Repository
```

The agent can understand it.

The developer can review it.

The tests can cover it.

The database stays behind the application boundary.

## Links

- NENE2: https://github.com/hideyukiMORI/NENE2
- nene-mcp: https://github.com/hideyukiMORI/nene-mcp
- First article: https://dev.to/hideyukimori/i-built-a-tiny-php-framework-for-ai-readable-business-apis-48eo
- GitHub profile: https://github.com/hideyukiMORI

I am still refining this approach through NENE2 and the surrounding NeNe OSS series.

If you are building MCP tools for business APIs, I would be interested to hear where you draw the line between useful automation and unsafe shortcuts.
