<?php

declare(strict_types=1);

namespace Nene2\Mcp;

use Nene2\FrameworkInfo;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * JSON-RPC 2.0 / MCP server that exposes framework API operations as tools.
 *
 * Run via `tools/local-mcp-server.php`. Reads its tool catalog from
 * `docs/mcp/tools.json` and proxies tool calls through {@see LocalMcpHttpClientInterface}.
 *
 * Non-`read` (state-changing) tools go through additional guards, aligned with the MCP
 * write-tool policy: bearer authentication is required, `destructive` tools require an
 * explicit confirmation flag, and every state-changing call is audited via `$logger`
 * (tool, safety level, operation, and the downstream API request id — never the
 * arguments or any secret). Authorization of the underlying operation is enforced at
 * the HTTP/JWT boundary the call is proxied through.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @phpstan-import-type McpTool from LocalMcpToolCatalog
 */
final readonly class LocalMcpServer
{
    public function __construct(
        private LocalMcpToolCatalog $catalog,
        private LocalMcpHttpClientInterface $httpClient,
        private string $apiBaseUrl,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;

        if (!is_string($method)) {
            return $this->error($id, -32600, 'JSON-RPC request must include a method.');
        }

        if (!array_key_exists('id', $message)) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->success($id, $this->initializeResult()),
                'tools/list' => $this->success($id, $this->toolsListResult()),
                'tools/call' => $this->success($id, $this->toolsCallResult($message['params'] ?? null)),
                default => $this->error($id, -32601, sprintf('Method "%s" is not supported.', $method)),
            };
        } catch (LocalMcpException $exception) {
            return $this->error($id, -32603, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeResult(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'nene2-local-mcp',
                'version' => FrameworkInfo::VERSION,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsListResult(): array
    {
        return [
            'tools' => array_map(
                fn (array $tool): array => [
                    'name' => $tool['name'],
                    'title' => $tool['title'],
                    'description' => $tool['description'],
                    'inputSchema' => $this->mcpInputSchema($tool['inputSchema']),
                    'annotations' => [
                        'readOnlyHint' => $tool['safety'] === 'read',
                    ],
                ],
                $this->catalog->tools(),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function mcpInputSchema(array $schema): array
    {
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * @param mixed $params
     * @return array<string, mixed>
     */
    private function toolsCallResult(mixed $params): array
    {
        if (!is_array($params)) {
            throw new LocalMcpException('tools/call params must be an object.');
        }

        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            throw new LocalMcpException('tools/call params.name must be a non-empty string.');
        }

        if (!is_array($arguments)) {
            throw new LocalMcpException('tools/call params.arguments must be an object when provided.');
        }

        $tool = $this->catalog->find($name);

        if ($tool === null) {
            throw new LocalMcpException(sprintf('MCP tool "%s" was not found.', $name));
        }

        $safety = $tool['safety'];

        if ($safety !== 'read' && !$this->httpClient->hasAuthentication()) {
            throw new LocalMcpException(
                sprintf(
                    'Write tool "%s" requires bearer authentication. Set NENE2_LOCAL_JWT_SECRET in the MCP server environment.',
                    $name,
                ),
            );
        }

        // Destructive tools require an explicit confirmation so an agent cannot delete or
        // purge data by inference. The flag is consumed here and never forwarded to the API.
        if ($safety === 'destructive') {
            if (($arguments['confirm'] ?? null) !== true) {
                throw new LocalMcpException(
                    sprintf('Destructive tool "%s" requires an explicit "confirm": true argument.', $name),
                );
            }

            unset($arguments['confirm']);
        }

        if ($tool['source']['type'] !== 'openapi') {
            throw new LocalMcpException(sprintf('MCP tool "%s" does not map to a local OpenAPI operation.', $name));
        }

        $result = $this->httpToolResult($tool, $arguments);

        if ($safety !== 'read') {
            $this->auditSensitiveCall($tool, $result);
        }

        return $result;
    }

    /**
     * Emits a structured audit record for a state-changing tool call. Records the tool,
     * safety level, operation, and the downstream API request id for correlation — never
     * the request arguments or any secret, per the logging policy.
     *
     * @param McpTool $tool
     * @param array<string, mixed> $result
     */
    private function auditSensitiveCall(array $tool, array $result): void
    {
        $structured = $result['structuredContent'] ?? null;

        $this->logger->info('MCP state-changing tool invoked.', [
            'tool' => $tool['name'],
            'safety' => $tool['safety'],
            'operation_id' => $tool['source']['operationId'],
            'method' => $tool['source']['method'],
            'status_code' => is_array($structured) ? ($structured['statusCode'] ?? null) : null,
            'request_id' => is_array($structured) ? ($structured['requestId'] ?? null) : null,
            'is_error' => $result['isError'] ?? null,
        ]);
    }

    /**
     * @param McpTool $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function httpToolResult(array $tool, array $arguments): array
    {
        [$path, $remainingArgs] = $this->interpolatePath($tool['source']['path'], $arguments);
        $method = $tool['source']['method'];

        $response = match ($method) {
            'GET'    => $this->httpClient->get($this->apiBaseUrl, $this->appendQuery($path, $remainingArgs)),
            'POST'   => $this->httpClient->post($this->apiBaseUrl, $path, $remainingArgs),
            'PUT'    => $this->httpClient->put($this->apiBaseUrl, $path, $remainingArgs),
            'PATCH'  => $this->httpClient->patch($this->apiBaseUrl, $path, $remainingArgs),
            'DELETE' => $this->httpClient->delete($this->apiBaseUrl, $path),
            default  => throw new LocalMcpException(sprintf('HTTP method "%s" is not supported.', $method)),
        };

        $body = $this->decodeBody($response->body);

        $structuredContent = [
            'tool' => $tool['name'],
            'operationId' => $tool['source']['operationId'],
            'statusCode' => $response->statusCode,
            'requestId' => $response->requestId(),
            'body' => $body,
        ];

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ],
            ],
            'structuredContent' => $structuredContent,
            'isError' => !$response->isSuccessful(),
        ];
    }

    /**
     * Splits path parameters from arguments, returning the interpolated path
     * and the remaining arguments (for body or query string).
     *
     * @param array<string, mixed> $arguments
     * @return array{string, array<string, mixed>}
     */
    private function interpolatePath(string $path, array $arguments): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $pathParams = $matches[1];

        $remaining = $arguments;

        foreach ($pathParams as $param) {
            if (!array_key_exists($param, $arguments)) {
                throw new LocalMcpException(sprintf('Required path parameter "%s" was not provided.', $param));
            }

            $path = str_replace('{' . $param . '}', rawurlencode((string) $arguments[$param]), $path);
            unset($remaining[$param]);
        }

        return [$path, $remaining];
    }

    /**
     * @param array<string, mixed> $queryArgs
     */
    private function appendQuery(string $path, array $queryArgs): string
    {
        if ($queryArgs === []) {
            return $path;
        }

        return $path . '?' . http_build_query($queryArgs);
    }

    private function decodeBody(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function success(mixed $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param mixed $id
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
