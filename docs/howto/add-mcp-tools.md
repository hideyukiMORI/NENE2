# Add MCP Tools

This guide shows how to expose your NENE2 application's API endpoints as MCP tools,
allowing AI assistants (Claude, Cursor, etc.) to call your API through the Model Context Protocol.

**Prerequisite**: You have a working NENE2 application with at least one route and an
`docs/openapi/openapi.yaml` file. If not, start with [Add a custom route](./add-custom-route.md).

---

## Overview

NENE2 ships a local MCP server (`LocalMcpServer`) that translates JSON-RPC MCP messages
into HTTP calls against your API. The tool catalog (`docs/mcp/tools.json`) declares which
endpoints are available as MCP tools and how safe each is to invoke.

```
AI assistant → JSON-RPC (stdio) → LocalMcpServer → HTTP → your NENE2 app
```

The catalog is validated against your OpenAPI spec with `composer mcp`.

---

## 1. Add the validator script

In `composer.json`, add:

```json
{
  "require-dev": {
    "symfony/yaml": "^7.0"
  },
  "scripts": {
    "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
  }
}
```

Then install the dev dependency:

```bash
composer require --dev symfony/yaml
```

---

## 2. Create the tool catalog

Create `docs/mcp/tools.json`. Each entry in `tools` corresponds to one API endpoint.

```json
{
  "version": 1,
  "source": "docs/openapi/openapi.yaml",
  "tools": [
    {
      "name": "listNotes",
      "title": "List Notes",
      "description": "Return all notes from the database.",
      "safety": "read",
      "source": {
        "type": "openapi",
        "operationId": "listNotes",
        "method": "GET",
        "path": "/notes"
      },
      "inputSchema": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "limit":  { "type": "integer", "description": "Maximum results to return." },
          "offset": { "type": "integer", "description": "Number of results to skip." }
        }
      },
      "responseSchemaRef": "#/components/schemas/NoteListResponse"
    },
    {
      "name": "getNoteById",
      "title": "Get Note",
      "description": "Return a single note by ID.",
      "safety": "read",
      "source": {
        "type": "openapi",
        "operationId": "getNoteById",
        "method": "GET",
        "path": "/notes/{id}"
      },
      "inputSchema": {
        "type": "object",
        "additionalProperties": false,
        "required": ["id"],
        "properties": {
          "id": { "type": "integer", "description": "Note ID." }
        }
      },
      "responseSchemaRef": "#/components/schemas/NoteResponse"
    }
  ]
}
```

### Tool fields

| Field | Required | Description |
|---|---|---|
| `name` | Yes | Unique camelCase identifier |
| `title` | Yes | Human-readable label |
| `description` | Yes | Shown to AI assistants to explain purpose |
| `safety` | Yes | `read` / `write` / `admin` / `destructive` |
| `source.operationId` | Yes | Must match an `operationId` in your OpenAPI spec |
| `source.method` | Yes | HTTP method (case-insensitive, stored uppercase) |
| `source.path` | Yes | URL path, with `{param}` for path parameters |
| `inputSchema` | Yes | JSON Schema for the tool's arguments |
| `responseSchemaRef` | No | `$ref` to an OpenAPI component schema, or `null` |

### Safety levels

| Level | Meaning |
|---|---|
| `read` | Safe to call without side effects (GET requests) |
| `write` | Creates or modifies data (POST / PUT / PATCH) |
| `admin` | Administrative action — use with care |
| `destructive` | Permanently deletes data — requires explicit confirmation |

Start with `read`-only tools and add `write` tools incrementally once you have authentication in place.

---

## 3. Validate the catalog

```bash
composer mcp
```

The validator checks:

- Every tool's `operationId` exists in your OpenAPI spec.
- Every tool's path matches the OpenAPI path definition.
- The `safety` field is one of the four allowed values.
- `responseSchemaRef` (when non-null) resolves to a real component schema.

Fix any reported errors before starting the MCP server.

---

## 4. Add write tools

Write tools call `POST`, `PUT`, `PATCH`, or `DELETE` endpoints. Add them to the catalog the
same way — only the `safety` level and `inputSchema` change:

```json
{
  "name": "createNote",
  "title": "Create Note",
  "description": "Create a new note.",
  "safety": "write",
  "source": {
    "type": "openapi",
    "operationId": "createNote",
    "method": "POST",
    "path": "/notes"
  },
  "inputSchema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["title", "content"],
    "properties": {
      "title":   { "type": "string", "description": "Note title." },
      "content": { "type": "string", "description": "Note body text." }
    }
  },
  "responseSchemaRef": null
}
```

`LocalMcpServer` sends string-type arguments as JSON request body fields. Integer-type
arguments in the path (e.g. `{id}`) are interpolated into the URL.

---

## 5. Protect write tools with JWT

`LocalMcpServer` checks for an `Authorization: Bearer <token>` header on every `write`,
`admin`, and `destructive` tool call. Set `NENE2_LOCAL_JWT_SECRET` in your environment:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-secret
```

Without this variable, write tool calls return an MCP error rather than forwarding the request.

In your application, protect the corresponding endpoints with `BearerTokenMiddleware`:

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret = getenv('NENE2_LOCAL_JWT_SECRET') ?: null;

$authMiddleware = $secret !== null
    ? new BearerTokenMiddleware(
        $problemDetails,
        new LocalBearerTokenVerifier($secret),
        excludedPaths: ['/notes'],           // GET /notes is public
        protectedPathPrefixes: ['/notes/'],  // POST /notes, etc. are protected
    )
    : null;

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: $authMiddleware,
    routeRegistrars: [$registerRoutes],
))->create();
```

Issue a token for the AI assistant and pass it in the MCP server configuration (see step 6).

---

## 6. Start the MCP server

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8080 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

The server reads from `stdin` and writes to `stdout` using the MCP stdio transport.

> When using Docker, run the server inside the container and expose it via a wrapper script:
>
> ```bash
> #!/bin/bash
> docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app \
>   -e NENE2_LOCAL_JWT_SECRET=your-local-secret \
>   app php vendor/hideyukimori/nene2/tools/local-mcp-server.php
> ```

---

## 7. Configure Claude Code or Claude Desktop

### Claude Code (`~/.claude/claude_code_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "/path/to/my-app/mcp-server.sh"
    }
  }
}
```

### Claude Desktop (`claude_desktop_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "bash",
      "args": ["/path/to/my-app/mcp-server.sh"]
    }
  }
}
```

After restarting Claude, the tools declared in your catalog appear as callable actions.

---

## 8. Test the MCP layer

Test `LocalMcpToolCatalog` directly — no HTTP server required:

```php
use Nene2\Mcp\LocalMcpToolCatalog;

public function testListNotesToolIsPresent(): void
{
    $catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');

    $tool = $catalog->find('listNotes');

    self::assertNotNull($tool);
    self::assertSame('read', $tool['safety']);
    self::assertSame('GET', $tool['source']['method']);
    self::assertSame('/notes', $tool['source']['path']);
}

public function testCreateNoteToolIsWrite(): void
{
    $catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');

    $tool = $catalog->find('createNote');

    self::assertNotNull($tool);
    self::assertSame('write', $tool['safety']);
}
```

---

## Path parameter interpolation

`LocalMcpServer` interpolates integer-typed input schema properties whose names appear in
the path as `{name}` placeholders. String-typed properties that do not appear in the path
are sent as query parameters (GET) or JSON body fields (POST / PUT / PATCH).

Example: for path `/notes/{id}` with `inputSchema` property `"id": { "type": "integer" }`,
a call with `{ "id": 42 }` becomes `GET /notes/42`.

---

## Design notes

- `LocalMcpServer` and `LocalMcpToolCatalog` track the MCP specification. If the MCP
  protocol introduces a breaking change, NENE2 will adapt and document it as an exception
  to the API stability rule (see ADR 0009, section 5).
- The catalog is intentionally separate from the OpenAPI spec so that not every API
  endpoint is exposed to AI tools by default — you opt in explicitly.
- `safety: "read"` tools call your API without authentication. Ensure public GET endpoints
  do not expose sensitive data before exposing them as MCP tools.

---

## Next steps

- [Add JWT authentication](./add-jwt-authentication.md) — protect write tool endpoints
- [Add rate limiting](./add-rate-limiting.md) — prevent AI assistants from overwhelming your API
- [Add a health check](./add-health-check.md) — the `getHealth` tool is the standard first MCP tool
