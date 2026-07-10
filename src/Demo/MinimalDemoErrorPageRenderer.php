<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Bundled locale-independent {@see DemoErrorPageRendererInterface}: a minimal,
 * unbranded English card explaining why the demo did not start (429 with a
 * "try again in about N minutes" hint, 503 fully booked, 404 unavailable).
 *
 * This default exists so every adopter of `Nene2\Demo` gets a humane browser
 * error out of the box; products are expected to replace it with their own
 * renderer carrying product copy, language, and brand (see
 * `docs/howto/add-disposable-demo.md` for a worked example).
 *
 * The page is fully self-contained and carries its own per-page
 * `Content-Security-Policy` allowing only its inline `<style>` block —
 * necessary because consuming apps typically run
 * {@see \Nene2\Middleware\SecurityHeadersMiddleware} with an app-wide
 * `default-src 'self'`, which blocks inline styles. That middleware only adds
 * headers that are absent, so the page's own CSP survives. This is safe
 * precisely because the page never contains request input: all copy is fixed
 * text plus server-computed numbers. Custom renderers using inline styles must
 * ship the same kind of per-page CSP or render unstyled.
 *
 * The class name and constructor are part of the public API stability
 * guarantee (see ADR 0009); the rendered markup and copy are presentation, not
 * contract, and may change in minor releases.
 */
final readonly class MinimalDemoErrorPageRenderer implements DemoErrorPageRendererInterface
{
    /**
     * Only the page's own inline `<style>` is allowed; everything else is
     * locked down. No scripts, no external assets, no request input.
     */
    private const string CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";

    public function __construct(
        private ResponseFactoryInterface $responseFactory = new Psr17Factory(),
    ) {
    }

    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', self::CSP);

        $response->getBody()->write($this->page($statusCode, $retryAfterSeconds));

        return $response;
    }

    private function page(int $statusCode, ?int $retryAfterSeconds): string
    {
        [$title, $lead] = $this->copyFor($statusCode, $retryAfterSeconds);
        $hint = $this->retryHint($statusCode, $retryAfterSeconds);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>{$title}</title>
            <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
                   font-family: system-ui, sans-serif; line-height: 1.6; color: #1f2933; background: #f5f7f7; }
            main { max-width: 440px; background: #fff; border: 1px solid #d9e0e0; border-radius: 6px; padding: 28px 30px; }
            h1 { margin: 0 0 10px; font-size: 19px; }
            p { margin: 0 0 8px; font-size: 14px; color: #4a5568; }
            .hint { margin-top: 14px; padding: 10px 14px; background: #f0f4f4; border-radius: 4px; font-size: 13px; }
            footer { margin-top: 18px; font-size: 11px; color: #9aa5b1; }
            </style>
            </head>
            <body>
            <main role="alert">
            <h1>{$title}</h1>
            <p>{$lead}</p>
            {$hint}<footer>HTTP {$statusCode}</footer>
            </main>
            </body>
            </html>
            HTML;
    }

    /** @return array{0: string, 1: string} [title, lead] */
    private function copyFor(int $statusCode, ?int $retryAfterSeconds): array
    {
        return match ($statusCode) {
            429 => [
                'The demo is in high demand',
                'Quite a few demos have been started from your network recently, so new starts are paused for a moment.',
            ],
            503 => [
                'The demo is fully booked right now',
                'All demo environments are currently in use. Old demos are cleaned up automatically, so space frees up on its own.',
            ],
            404 => [
                'This demo is not available',
                'The link may have changed, or the demo is switched off here. Please check the link you were given.',
            ],
            default => [
                'The demo could not be started',
                'A temporary problem occurred while preparing your demo environment.',
            ],
        };
    }

    private function retryHint(int $statusCode, ?int $retryAfterSeconds): string
    {
        if ($statusCode === 404) {
            return '';
        }

        if ($statusCode === 429 && $retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $minutes = max(1, (int) ceil($retryAfterSeconds / 60));
            $unit = $minutes === 1 ? 'minute' : 'minutes';

            return "<p class=\"hint\">Please open this link again in about {$minutes} {$unit} — the pause lifts automatically.</p>\n";
        }

        return "<p class=\"hint\">Please open this link again in a little while.</p>\n";
    }
}
