# AI-Assisted Debugging

AI-assisted debugging should start from stable runtime evidence, not guesses.

For HTTP behavior, the first correlation value is the request id.

## Request ID Workflow

When debugging a local API response:

1. Call the public HTTP route.
2. Capture the `X-Request-Id` response header.
3. Use the request id to correlate logs, Problem Details responses, and future metrics or error tracking events.
4. Share only the minimal safe context needed to diagnose the issue.

Example:

```bash
curl -i http://localhost:8080/health
```

The response should include:

```text
X-Request-Id: <request-id>
```

If the request fails with Problem Details, keep these fields together:

- `X-Request-Id` response header
- HTTP status
- Problem Details `type`
- Problem Details `title`
- request method and path

## Safe Context

Good AI debugging context includes:

- request id
- HTTP method
- route path or route pattern
- status code
- Problem Details `type`
- exception class when it is already in safe server logs
- short reproduction steps
- relevant command output from documented local checks

Do not include:

- `.env` values
- tokens, API keys, session ids, cookies, or `Authorization` headers
- raw request bodies that may contain private data
- database DSNs or credentials
- private filesystem paths unless they are already part of committed repository paths

## MCP Tooling Direction

Read-only MCP tools should return request id metadata when they call HTTP APIs and the response includes `X-Request-Id`.

Mutation, admin, and destructive MCP tools must define audit behavior before implementation. The audit record should include request id when the tool maps to HTTP behavior.

## Logging Relationship

NENE2 request logs should include `request_id`, `method`, `path`, `status`, and `duration_ms` when request logging is enabled.

AI debugging should use those structured fields as correlation points. It should not require raw request payload logging by default.

## Local Commands

Use the safe command policy in `docs/integrations/local-ai-commands.md` before running local checks during debugging.
