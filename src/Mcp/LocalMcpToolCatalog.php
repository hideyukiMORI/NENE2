<?php

declare(strict_types=1);

namespace Nene2\Mcp;

/**
 * Loads and validates the MCP tool catalog from a `docs/mcp/tools.json` file.
 *
 * Consumer projects use this class directly in tests to verify their tool catalog:
 * `new LocalMcpToolCatalog(__DIR__ . '/../docs/mcp/tools.json')`.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @phpstan-type McpToolSource array{type: string, operationId: string, method: string, path: string}
 * @phpstan-type McpTool array{name: string, title: string, description: string, safety: string, source: McpToolSource, inputSchema: array<string, mixed>, responseSchemaRef: string|null}
 */
final class LocalMcpToolCatalog
{
    /** @var list<McpTool>|null */
    private ?array $cachedTools = null;

    /** @var list<\Closure(McpTool): bool> */
    private array $filters = [];

    public function __construct(
        private readonly string $catalogPath,
    ) {
    }

    /**
     * Returns a copy of this catalog that exposes only the tools matching $filter.
     *
     * The predicate receives each fully validated tool (see the McpTool shape) and
     * returns true to keep it. Both {@see tools()} and {@see find()} on the returned
     * catalog honour the filter, so a consumer can hand the narrowed catalog straight
     * to {@see LocalMcpServer} to serve a subset (for example, read-only by default with
     * admin tools behind an explicit opt-in) without writing a filtered catalog to a
     * temporary file.
     *
     * Filters compose: chaining withFilter() narrows further — every predicate must pass.
     * The original catalog is left unchanged.
     *
     * @param callable(McpTool): bool $filter
     */
    public function withFilter(callable $filter): self
    {
        $new = clone $this;
        $new->filters = [...$this->filters, $filter(...)];
        $new->cachedTools = null;

        return $new;
    }

    /**
     * @return list<McpTool>
     */
    public function tools(): array
    {
        if ($this->cachedTools !== null) {
            return $this->cachedTools;
        }

        if (!is_file($this->catalogPath)) {
            throw new LocalMcpException(sprintf('MCP tool catalog could not be read from "%s".', $this->catalogPath));
        }

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

        $validTools = array_values(array_filter($tools, static fn (mixed $t) => is_array($t)));

        $normalized = array_map($this->tool(...), $validTools);

        foreach ($this->filters as $filter) {
            $normalized = array_values(array_filter($normalized, $filter));
        }

        return $this->cachedTools = $normalized;
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
            'responseSchemaRef' => $this->nullableStringValue($value, 'responseSchemaRef'),
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

    /**
     * @param array<string, mixed> $values
     */
    private function nullableStringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            throw new LocalMcpException(sprintf('MCP catalog field "%s" must be a non-empty string or null.', $key));
        }

        return $value;
    }
}
