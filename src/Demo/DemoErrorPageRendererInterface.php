<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Psr\Http\Message\ResponseInterface;

/**
 * Renders the browser-facing HTML page shown when a demo start fails.
 *
 * `GET /demo/{template}` is a public route that real people open in a browser
 * (a sales prospect clicking a referral link), so its errors must not surface
 * as raw RFC 9457 JSON — a non-technical visitor reads that as "the site is
 * broken". When the request's `Accept` header contains `text/html`,
 * {@see StartDisposableDemoHandler} replaces the Problem Details error with the
 * page produced by this renderer. Products override the bundled
 * {@see MinimalDemoErrorPageRenderer} to supply their own copy, language, and
 * brand.
 *
 * Contract for implementations:
 *
 * - Return a complete response carrying the HTML body and its `Content-Type`.
 *   Page-specific headers (e.g. a per-page `Content-Security-Policy` allowing
 *   your inline styles — see the CSP note on {@see MinimalDemoErrorPageRenderer})
 *   belong here too.
 * - **Never echo request input into the page.** The handler deliberately does
 *   not pass the request: all copy must be fixed text plus server-computed
 *   numbers, so the page cannot become an XSS vector.
 * - Do not reference external assets (stylesheets, fonts, images, scripts);
 *   the page must be fully self-contained.
 * - Include `<meta name="robots" content="noindex">`. The handler also forces
 *   `X-Robots-Tag: noindex` on the response, but the meta tag keeps the page
 *   self-describing.
 *
 * The handler enforces the transport invariants regardless of the renderer:
 * the response status is reset to the original error status, `Retry-After` is
 * copied over (429), and `X-Robots-Tag: noindex` is added.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DemoErrorPageRendererInterface
{
    /**
     * @param int $statusCode The Problem Details status being replaced (404, 429, 503, ...).
     * @param int|null $retryAfterSeconds Seconds until the client may retry, taken from the
     *        429 response's `Retry-After` header; null when the error carries none.
     */
    public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface;
}
