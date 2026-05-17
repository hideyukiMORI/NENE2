<?php

declare(strict_types=1);

namespace Nene2\Tests\Mcp;

use Nene2\Mcp\LocalMcpToolCatalog;
use PHPUnit\Framework\TestCase;

final class LocalMcpToolCatalogTest extends TestCase
{
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
}
