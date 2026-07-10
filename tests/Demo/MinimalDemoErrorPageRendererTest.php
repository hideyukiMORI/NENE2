<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use Nene2\Demo\MinimalDemoErrorPageRenderer;
use PHPUnit\Framework\TestCase;

final class MinimalDemoErrorPageRendererTest extends TestCase
{
    public function testCarriesTheBrowserPageTransportHeaders(): void
    {
        $response = (new MinimalDemoErrorPageRenderer())->render(429, 90);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testCarriesItsOwnPerPageCspAllowingOnlyItsInlineStyle(): void
    {
        $response = (new MinimalDemoErrorPageRenderer())->render(429, 90);
        $csp = $response->getHeaderLine('Content-Security-Policy');

        // The consuming app's SecurityHeadersMiddleware default (`default-src 'self'`)
        // blocks inline styles; the page survives because that middleware only adds
        // absent headers and this page ships its own CSP (ADR 0018).
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("style-src 'unsafe-inline'", $csp);
        self::assertStringNotContainsString('script-src', $csp, 'the page has no scripts, so none are allowed');
    }

    public function testPageIsSelfContainedAndNotIndexable(): void
    {
        $body = (string) (new MinimalDemoErrorPageRenderer())->render(429, 90)->getBody();

        self::assertStringContainsString('<meta name="robots" content="noindex">', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('http://', $body);
        self::assertStringNotContainsString('https://', $body);
        self::assertStringNotContainsString('src=', $body);
        self::assertStringNotContainsString('href=', $body);
    }

    public function testThrottledPageTurnsRetryAfterIntoAMinutesHint(): void
    {
        $renderer = new MinimalDemoErrorPageRenderer();

        self::assertStringContainsString('about 2 minutes', (string) $renderer->render(429, 90)->getBody());
        self::assertStringContainsString('about 1 minute', (string) $renderer->render(429, 30)->getBody());
        self::assertStringNotContainsString('about 1 minutes', (string) $renderer->render(429, 30)->getBody());
    }

    public function testThrottledPageWithoutRetryAfterFallsBackToAGenericHint(): void
    {
        $body = (string) (new MinimalDemoErrorPageRenderer())->render(429, null)->getBody();

        self::assertStringContainsString('in a little while', $body);
        self::assertStringNotContainsString('about', $body);
    }

    public function testEachStatusGetsItsOwnCopyAndTheStatusIsShown(): void
    {
        $renderer = new MinimalDemoErrorPageRenderer();

        $throttled = (string) $renderer->render(429, 60)->getBody();
        $full = (string) $renderer->render(503, null)->getBody();
        $missing = (string) $renderer->render(404, null)->getBody();
        $other = (string) $renderer->render(500, null)->getBody();

        self::assertStringContainsString('high demand', $throttled);
        self::assertStringContainsString('HTTP 429', $throttled);
        self::assertStringContainsString('fully booked', $full);
        self::assertStringContainsString('HTTP 503', $full);
        self::assertStringContainsString('not available', $missing);
        self::assertStringContainsString('could not be started', $other);
    }

    public function testNotFoundPageHasNoRetryHint(): void
    {
        $body = (string) (new MinimalDemoErrorPageRenderer())->render(404, null)->getBody();

        self::assertStringNotContainsString('open this link again', $body);
    }
}
