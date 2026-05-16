<?php

declare(strict_types=1);

namespace Nene2\Mcp;

/**
 * @phpstan-type McpToolSource array{type: string, operationId: string, method: string, path: string}
 * @phpstan-type McpTool array{name: string, title: string, description: string, safety: string, source: McpToolSource, inputSchema: array<string, mixed>, responseSchemaRef: string}
 */
final readonly class LocalMcpToolCatalog
{
    public function __construct(
        private string $catalogPath,
    ) {
    }

    /**
     * @return list<McpTool>
     */
    public function tools(): array
    {
        $contents = file_get_contents($this->catalogPath);

        if ($contents === false) {
            throw new LocalMcpException(sprintf('MCP tool catalog could not be read from "%s".', $this->catalogPath));
        }

        $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($catalog)) {
            throw new LocalMcpException('MCP tool catalog must parse to an object.');
        }

        $tools = $catalog['tools'] ?? null;

        if (!is_array($tools)) {
            throw new LocalMcpException('MCP tool catalog must contain a tools array.');
        }

        $readTools = array_values(array_filter($tools, static fn (mixed $t) => is_array($t) && ($t['safety'] ?? null) === 'read'));

        return array_map($this->tool(...), $readTools);
    }

    /**
     * @return McpTool|null
     */
    public function find(string $name): ?array
    {
        foreach ($this->tools() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return McpTool
     */
    private function tool(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LocalMcpException('Each MCP catalog tool must be an object.');
        }

        $source = $value['source'] ?? null;
        $inputSchema = $value['inputSchema'] ?? null;

        if (!is_array($source) || !is_array($inputSchema)) {
            throw new LocalMcpException('Each MCP catalog tool must define source and inputSchema objects.');
        }

        return [
            'name' => $this->stringValue($value, 'name'),
            'title' => $this->stringValue($value, 'title'),
            'description' => $this->stringValue($value, 'description'),
            'safety' => $this->stringValue($value, 'safety'),
            'source' => [
                'type' => $this->stringValue($source, 'type'),
                'operationId' => $this->stringValue($source, 'operationId'),
                'method' => strtoupper($this->stringValue($source, 'method')),
                'path' => $this->stringValue($source, 'path'),
            ],
            'inputSchema' => $inputSchema,
            'responseSchemaRef' => $this->stringValue($value, 'responseSchemaRef'),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new LocalMcpException(sprintf('MCP catalog field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }
}
