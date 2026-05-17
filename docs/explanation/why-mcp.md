# Why MCP as the AI Integration Boundary?

NENE2 integrates with AI agents through the Model Context Protocol (MCP) rather than giving agents direct database or filesystem access. This page explains the design decision.

## What the boundary looks like

```
AI agent (Claude, Cursor, …)
    │  MCP stdio
    ▼
local-mcp-server.php          ← NENE2's MCP server
    │  HTTP
    ▼
NENE2 API (PSR-7 / OpenAPI)   ← same endpoints the browser uses
    │  PDO
    ▼
Database
```

The AI agent never reaches the database directly. Every operation goes through a documented HTTP endpoint with request validation, authentication, and structured error responses.

## Why not let agents query the database directly?

### 1. The API contract is the source of truth

The OpenAPI document describes what operations exist, what inputs they accept, and what outputs they return. SQL queries bypass this contract. An agent that writes SQL can silently touch data outside the intended scope of an operation.

### 2. Authorization lives at the API layer

API-key authentication, CORS policy, and request-size limits are enforced in PSR-15 middleware. A direct database connection skips all of these.

### 3. Structured errors help agents recover

When an API call fails, the agent receives a Problem Details response with a machine-readable `type` and structured `errors`. A failed SQL query returns a database error string that the agent must parse or ignore.

### 4. The same endpoints serve all clients

The MCP server calls the same routes as a browser, a test suite, or a curl command. This means:

- OpenAPI tests validate MCP-reachable behavior.
- Request logging captures MCP operations alongside human requests.
- No separate database testing surface is needed for MCP paths.

## Why MCP specifically?

MCP is an open protocol with a growing ecosystem of clients (Claude, Cursor, Zed, VS Code, …). Implementing MCP rather than a proprietary AI integration means the same `local-mcp-server.php` works across clients without modification.

The MCP server is also a stdio process, not an HTTP server. This means it has no open port and cannot be accidentally reached from outside the development machine.

## Tool safety levels

NENE2 MCP tools carry an explicit safety level:

| Level | Examples | Requirements |
|-------|----------|-------------|
| `read` | `getHealth`, `getNote` | None beyond API key |
| `write` | `createNote`, `updateNote` | Same as above |
| `admin` | Hypothetical role changes | Explicit confirmation step |
| `destructive` | Batch deletes | Out of scope for local dev |

Starting with `read` tools and adding `write` tools only when explicitly scoped reduces the risk of an agent making unintended state changes.

## Further reading

- `docs/integrations/mcp-tools.md` — tool catalog policy
- `docs/integrations/local-mcp-server.md` — running the local server
- `docs/mcp/tools.json` — current tool definitions
