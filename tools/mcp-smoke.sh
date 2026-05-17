#!/usr/bin/env bash
# Local MCP smoke helper.
# Pipes JSON-RPC messages to the stdio MCP server against the running app service.
#
# Preconditions:
#   Read tools (getHealth, listExampleNotes, getExampleNoteById, ...):
#     docker compose up -d app
#
#   Write tools (createExampleNote, updateExampleNoteById, deleteExampleNoteById):
#     docker compose up -d app mysql
#     docker compose run --rm app composer migrations:migrate
#
# Usage:
#   bash tools/mcp-smoke.sh
#   bash tools/mcp-smoke.sh getHealth '{}'
#   bash tools/mcp-smoke.sh createExampleNote '{"title":"hello","body":"world"}'
#   bash tools/mcp-smoke.sh updateExampleNoteById '{"id":1,"title":"updated","body":"body"}'
#   bash tools/mcp-smoke.sh deleteExampleNoteById '{"id":1}'
#
# Environment:
#   NENE2_LOCAL_API_BASE_URL  override API base URL (default: http://app)

set -euo pipefail

API_BASE="${NENE2_LOCAL_API_BASE_URL:-http://app}"
TOOL="${1:-}"
ARGS="${2:-}"
[[ -z "${ARGS}" ]] && ARGS="{}"

MESSAGES=(
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"mcp-smoke","version":"0.0.0"}}}'
    '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
)

if [[ -n "${TOOL}" ]]; then
    MESSAGES+=("{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"${TOOL}\",\"arguments\":${ARGS}}}")
fi

printf '%s\n' "${MESSAGES[@]}" \
    | docker compose run --rm -T \
        -e "NENE2_LOCAL_API_BASE_URL=${API_BASE}" \
        app php tools/local-mcp-server.php
