<?php

declare(strict_types=1);

namespace Nene2\Tests\Mcp;

use Nene2\Mcp\LocalMcpException;
use Nene2\Mcp\LocalMcpToolCatalog;
use PHPUnit\Framework\TestCase;

final class LocalMcpToolCatalogTest extends TestCase
{
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->catalogPath = sys_get_temp_dir() . '/nene2-mcp-test-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->catalogPath)) {
            unlink($this->catalogPath);
        }
    }

    // -----------------------------------------------------------------------
    // Existing catalog (committed)
    // -----------------------------------------------------------------------

    public function testLoadsReadOnlyToolsFromCommittedCatalog(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $tool = $catalog->find('getHealth');

        self::assertNotNull($tool);
        self::assertSame('read', $tool['safety']);
        self::assertSame('GET', $tool['source']['method']);
        self::assertSame('/health', $tool['source']['path']);
        self::assertSame('getHealth', $tool['source']['operationId']);
    }

    public function testLoadsWriteToolsFromCommittedCatalog(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $tool = $catalog->find('createExampleNote');

        self::assertNotNull($tool);
        self::assertSame('write', $tool['safety']);
        self::assertSame('POST', $tool['source']['method']);
        self::assertSame('/examples/notes', $tool['source']['path']);
        self::assertNull($tool['responseSchemaRef']);
    }

    public function testAllToolsArePresentInToolsList(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $names = array_column($catalog->tools(), 'name');

        self::assertContains('getHealth', $names);
        self::assertContains('createExampleNote', $names);
        self::assertContains('updateExampleNoteById', $names);
        self::assertContains('deleteExampleNoteById', $names);
    }

    // -----------------------------------------------------------------------
    // withFilter() — serve a subset without a temp catalog file
    // -----------------------------------------------------------------------

    public function testWithFilterNarrowsToolsToMatchingSubset(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $readOnly = $catalog->withFilter(static fn (array $tool): bool => $tool['safety'] === 'read');

        $names = array_column($readOnly->tools(), 'name');

        self::assertContains('getHealth', $names);
        self::assertNotContains('createExampleNote', $names);
        self::assertNotContains('deleteExampleNoteById', $names);
        self::assertSame(['read'], array_values(array_unique(array_column($readOnly->tools(), 'safety'))));
    }

    public function testWithFilterAlsoAppliesToFind(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $readOnly = $catalog->withFilter(static fn (array $tool): bool => $tool['safety'] === 'read');

        self::assertNotNull($readOnly->find('getHealth'));
        self::assertNull($readOnly->find('createExampleNote'));
    }

    public function testWithFilterLeavesTheOriginalCatalogUnchanged(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $catalog->withFilter(static fn (array $tool): bool => $tool['safety'] === 'read');

        // The original still exposes write tools — withFilter returns a copy.
        self::assertNotNull($catalog->find('createExampleNote'));
    }

    public function testWithFilterComposesByNarrowingFurther(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        $narrowed = $catalog
            ->withFilter(static fn (array $tool): bool => $tool['safety'] === 'read')
            ->withFilter(static fn (array $tool): bool => $tool['name'] === 'getHealth');

        self::assertSame(['getHealth'], array_column($narrowed->tools(), 'name'));
    }

    public function testWithFilterCompositionCannotWidenBackAnEarlierFilter(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        // First keep only getHealth (a read tool), then ask for write tools:
        // composition ANDs the predicates, so the result is empty rather than
        // re-exposing write tools the first filter removed.
        $narrowed = $catalog
            ->withFilter(static fn (array $tool): bool => $tool['name'] === 'getHealth')
            ->withFilter(static fn (array $tool): bool => $tool['safety'] === 'write');

        self::assertSame([], $narrowed->tools());
    }

    // -----------------------------------------------------------------------
    // File / parse errors
    // -----------------------------------------------------------------------

    public function testThrowsWhenCatalogFileDoesNotExist(): void
    {
        $catalog = new LocalMcpToolCatalog('/nonexistent/path/tools.json');

        $this->expectException(LocalMcpException::class);

        $catalog->tools();
    }

    public function testThrowsOnInvalidJson(): void
    {
        file_put_contents($this->catalogPath, 'not json {{');

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(\JsonException::class);

        $catalog->tools();
    }

    public function testThrowsWhenCatalogRootIsNotObject(): void
    {
        // json_decode with assoc=true turns a JSON array into a PHP list array.
        // The 'tools' key lookup then fails, producing the "tools array" message.
        file_put_contents($this->catalogPath, json_encode([1, 2, 3]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('must contain a tools array');

        $catalog->tools();
    }

    public function testThrowsWhenToolsKeyIsMissing(): void
    {
        file_put_contents($this->catalogPath, json_encode(['version' => 1]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('must contain a tools array');

        $catalog->tools();
    }

    // -----------------------------------------------------------------------
    // Individual tool validation
    // -----------------------------------------------------------------------

    public function testThrowsWhenToolMissingSourceKey(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'myTool',
                'title' => 'My Tool',
                'description' => 'desc',
                'safety' => 'read',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                // 'source' is intentionally missing
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('source');

        $catalog->tools();
    }

    public function testThrowsWhenToolMissingInputSchemaKey(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'myTool',
                'title' => 'My Tool',
                'description' => 'desc',
                'safety' => 'read',
                'source' => ['type' => 'openapi', 'operationId' => 'op', 'method' => 'GET', 'path' => '/'],
                // 'inputSchema' is intentionally missing
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('inputSchema');

        $catalog->tools();
    }

    public function testThrowsWhenResponseSchemaRefIsEmptyString(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'myTool',
                'title' => 'My Tool',
                'description' => 'desc',
                'safety' => 'read',
                'source' => ['type' => 'openapi', 'operationId' => 'op', 'method' => 'GET', 'path' => '/'],
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'responseSchemaRef' => '',
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('responseSchemaRef');

        $catalog->tools();
    }

    // -----------------------------------------------------------------------
    // find() behaviour
    // -----------------------------------------------------------------------

    public function testFindReturnsNullForUnknownToolName(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json');

        self::assertNull($catalog->find('nonExistentTool'));
    }

    // -----------------------------------------------------------------------
    // Safety levels
    // -----------------------------------------------------------------------

    public function testAdminSafetyLevelIsAccepted(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'adminTool',
                'title' => 'Admin Tool',
                'description' => 'admin',
                'safety' => 'admin',
                'source' => ['type' => 'openapi', 'operationId' => 'op', 'method' => 'DELETE', 'path' => '/admin'],
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'responseSchemaRef' => null,
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);
        $tool = $catalog->find('adminTool');

        self::assertNotNull($tool);
        self::assertSame('admin', $tool['safety']);
    }

    public function testDestructiveSafetyLevelIsAccepted(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'nukeAll',
                'title' => 'Nuke All',
                'description' => 'destructive',
                'safety' => 'destructive',
                'source' => ['type' => 'openapi', 'operationId' => 'nukeAll', 'method' => 'DELETE', 'path' => '/all'],
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'responseSchemaRef' => null,
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);
        $tool = $catalog->find('nukeAll');

        self::assertNotNull($tool);
        self::assertSame('destructive', $tool['safety']);
    }

    // -----------------------------------------------------------------------
    // HTTP method normalisation
    // -----------------------------------------------------------------------

    public function testSourceMethodIsUppercased(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'myTool',
                'title' => 'My Tool',
                'description' => 'desc',
                'safety' => 'write',
                'source' => ['type' => 'openapi', 'operationId' => 'op', 'method' => 'post', 'path' => '/items'],
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'responseSchemaRef' => null,
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);
        $tool = $catalog->find('myTool');

        self::assertNotNull($tool);
        self::assertSame('POST', $tool['source']['method']);
    }

    // -----------------------------------------------------------------------
    // responseSchemaRef
    // -----------------------------------------------------------------------

    public function testResponseSchemaRefIsNullWhenAbsent(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'tools' => [[
                'name' => 'myTool',
                'title' => 'My Tool',
                'description' => 'desc',
                'safety' => 'read',
                'source' => ['type' => 'openapi', 'operationId' => 'op', 'method' => 'GET', 'path' => '/'],
                'inputSchema' => ['type' => 'object', 'properties' => []],
                // responseSchemaRef absent — should default to null
            ]],
        ]));

        $catalog = new LocalMcpToolCatalog($this->catalogPath);
        $tool = $catalog->find('myTool');

        self::assertNotNull($tool);
        self::assertNull($tool['responseSchemaRef']);
    }
}
