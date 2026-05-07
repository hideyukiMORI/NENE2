<?php

declare(strict_types=1);

namespace Nene2\Mcp;

/**
 * @phpstan-import-type McpTool from LocalMcpToolCatalog
 */
final readonly class LocalMcpServer
{
    public function __construct(
        private LocalMcpToolCatalog $catalog,
        private LocalMcpHttpClientInterface $httpClient,
        private string $apiBaseUrl,
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
                'version' => '0.1.0',
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

        if ($arguments !== []) {
            throw new LocalMcpException('The first NENE2 local MCP tools do not accept arguments.');
        }

        $tool = $this->catalog->find($name);

        if ($tool === null) {
            throw new LocalMcpException(sprintf('MCP tool "%s" was not found.', $name));
        }

        if ($tool['safety'] !== 'read') {
            throw new LocalMcpException(sprintf('MCP tool "%s" is not read-only.', $name));
        }

        if ($tool['source']['type'] !== 'openapi' || $tool['source']['method'] !== 'GET') {
            throw new LocalMcpException(sprintf('MCP tool "%s" does not map to a local GET API operation.', $name));
        }

        return $this->httpToolResult($tool);
    }

    /**
     * @param McpTool $tool
     * @return array<string, mixed>
     */
    private function httpToolResult(array $tool): array
    {
        $response = $this->httpClient->get($this->apiBaseUrl, $tool['source']['path']);
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
