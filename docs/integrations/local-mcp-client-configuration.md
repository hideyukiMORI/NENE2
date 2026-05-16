# Local MCP Client Configuration

This guide shows how to connect a local MCP client to the NENE2 stdio MCP server.

It is for local development only. Do not reuse this configuration for production MCP deployment.

## Preconditions

Build the PHP image and start the local API:

```bash
docker compose build app
docker compose up -d app
```

Confirm the API is reachable:

```bash
curl -i http://localhost:8080/health
```

The MCP server is a stdio process. It is not an HTTP server and should be launched by an MCP client.

## Generic Stdio Configuration

Use this shape in MCP clients that accept a command, arguments, and environment variables:

```json
{
  "mcpServers": {
    "nene2-local": {
      "command": "docker",
      "args": [
        "compose",
        "run",
        "--rm",
        "-e",
        "NENE2_LOCAL_API_BASE_URL=http://app",
        "app",
        "php",
        "tools/local-mcp-server.php"
      ]
    }
  }
}
```

Why `http://app`:

- the MCP server process runs inside a Docker Compose `app` container
- the target web service is reachable by Compose service name
- `localhost` from inside that container would refer to the one-off MCP container, not the running web service

Do not put secrets in committed MCP client configuration. If a future local tool needs a credential, pass only the environment variable name in docs and set the value outside the repository.

## Local Smoke Check

You can smoke test the server without a full MCP client by piping newline-delimited JSON-RPC messages.

Start with `initialize`:

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

List tools:

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

Call the health tool:

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

The response should include structured content from the documented local HTTP API and may include request id metadata.

## Available Tools

The first local server loads read-only tools from `docs/mcp/tools.json`.

Current examples:

- `getFrameworkSmoke`
- `getHealth`

Validate the catalog with:

```bash
docker compose run --rm app composer mcp
```

### Path Parameter Types

Tools that map to OpenAPI paths with integer parameters (e.g., `{year}`, `{id}`) require JSON number values in `tools/call` arguments, not strings.

Correct:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

Incorrect (will be rejected when the schema specifies `integer`):

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

Check the tool's `inputSchema` in `docs/mcp/tools.json` to confirm the expected type for each parameter.

## Safety Rules

Local MCP clients may:

- call documented local HTTP APIs
- read committed MCP metadata through the server
- use read-only tools aligned with OpenAPI operations

Local MCP clients must not:

- read `.env` secrets
- call production APIs
- expose direct database or filesystem access
- add write, admin, or destructive tools without a focused design and Issue
- commit user-specific MCP client configuration

## Related Work

- Local MCP server guidance: `docs/integrations/local-mcp-server.md`
- MCP tool policy: `docs/integrations/mcp-tools.md`
- MCP catalog: `docs/mcp/tools.json`
- Client project start guide: `docs/development/client-project-start.md`
- Authentication boundary: `docs/development/authentication-boundary.md`
- GitHub Issue: `#152`
