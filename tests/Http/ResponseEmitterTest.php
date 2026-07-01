<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\ResponseEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the body-emission contract. Header emission relies on `header()` /
 * `http_response_code()`, which are no-ops under the CLI SAPI, so these tests
 * assert only on the emitted body captured from the output buffer.
 */
final class ResponseEmitterTest extends TestCase
{
    #[Test]
    public function emitsBodyForGetRequests(): void
    {
        $response = (new Psr17Factory())->createResponse(200)
            ->withBody((new Psr17Factory())->createStream('{"ok":true}'));

        ob_start();
        (new ResponseEmitter())->emit($response, 'GET');
        $output = (string) ob_get_clean();

        self::assertSame('{"ok":true}', $output);
    }

    #[Test]
    public function suppressesBodyForHeadRequests(): void
    {
        // RFC 7231 §4.3.2: a HEAD response must not include a message body, even
        // though the router produced one via the GET handler (#1443).
        $response = (new Psr17Factory())->createResponse(200)
            ->withBody((new Psr17Factory())->createStream('{"secret":"data"}'));

        ob_start();
        (new ResponseEmitter())->emit($response, 'HEAD');
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    #[Test]
    public function headMatchIsCaseInsensitive(): void
    {
        $response = (new Psr17Factory())->createResponse(200)
            ->withBody((new Psr17Factory())->createStream('body'));

        ob_start();
        (new ResponseEmitter())->emit($response, 'head');
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    #[Test]
    public function emitsBodyWhenMethodIsOmitted(): void
    {
        // Backward-compatible default: callers that do not pass a method still get the body.
        $response = (new Psr17Factory())->createResponse(200)
            ->withBody((new Psr17Factory())->createStream('legacy'));

        ob_start();
        (new ResponseEmitter())->emit($response);
        $output = (string) ob_get_clean();

        self::assertSame('legacy', $output);
    }
}
