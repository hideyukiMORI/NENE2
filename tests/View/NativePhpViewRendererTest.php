<?php

declare(strict_types=1);

namespace Nene2\Tests\View;

use Nene2\View\HtmlEscaper;
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nene2\View\TemplateNotFoundException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class NativePhpViewRendererTest extends TestCase
{
    private string $templateRoot;

    protected function setUp(): void
    {
        $this->templateRoot = sys_get_temp_dir() . '/nene2-view-test-' . bin2hex(random_bytes(6));
        mkdir($this->templateRoot);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templateRoot . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->templateRoot);
    }

    public function testEscaperEscapesHtmlSpecialCharacters(): void
    {
        $escaper = new HtmlEscaper();

        self::assertSame('&lt;script&gt;&quot;x&quot; &amp; &apos;y&apos;&lt;/script&gt;', $escaper->escape('<script>"x" & \'y\'</script>'));
    }

    public function testRendererPassesVariablesAndEscapingHelperToTemplate(): void
    {
        file_put_contents(
            $this->templateRoot . '/hello.php',
            '<h1><?= $e($title) ?></h1><p><?= $e($message) ?></p>',
        );

        $renderer = new NativePhpViewRenderer($this->templateRoot);

        self::assertSame(
            '<h1>Hello</h1><p>&lt;strong&gt;NENE2&lt;/strong&gt;</p>',
            $renderer->render('hello.php', [
                'title' => 'Hello',
                'message' => '<strong>NENE2</strong>',
            ]),
        );
    }

    public function testRendererRejectsMissingTemplate(): void
    {
        $renderer = new NativePhpViewRenderer($this->templateRoot);

        $this->expectException(TemplateNotFoundException::class);

        $renderer->render('missing.php');
    }

    public function testRendererRejectsParentDirectoryTraversal(): void
    {
        $renderer = new NativePhpViewRenderer($this->templateRoot);

        $this->expectException(TemplateNotFoundException::class);

        $renderer->render('../secret.php');
    }

    public function testHtmlResponseFactoryCreatesHtmlResponse(): void
    {
        file_put_contents($this->templateRoot . '/page.php', '<main><?= $e($content) ?></main>');

        $psr17Factory = new Psr17Factory();
        $responseFactory = new HtmlResponseFactory(
            $psr17Factory,
            $psr17Factory,
            new NativePhpViewRenderer($this->templateRoot),
        );

        $response = $responseFactory->create('page.php', ['content' => 'OK'], 201, ['X-View' => 'native']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('native', $response->getHeaderLine('X-View'));
        self::assertSame('<main>OK</main>', (string) $response->getBody());
    }
}
