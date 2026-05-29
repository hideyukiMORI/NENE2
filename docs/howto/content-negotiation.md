---
title: "Content negotiation"
category: api-design
tags: [content-negotiation, json, accept-header, problem-json]
difficulty: beginner
related: [content-negotiation-api, api-versioning]
---

# Content negotiation

NENE2 is a JSON-first framework. It does not implement content negotiation — all responses use `application/json` (or `application/problem+json` for errors) regardless of what the client sends in the `Accept` header.

## What NENE2 does

| Client sends | Server returns |
|---|---|
| No `Accept` header | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 never returns `406 Not Acceptable`.** RFC 7231 §6.5.6 says the server SHOULD return 406 when no acceptable type is available, but this is a SHOULD (not MUST). For a JSON-only API server, always returning JSON is the simplest and most common choice.

Error responses use `application/problem+json` (RFC 9457) regardless of `Accept`:

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## Request body Content-Type

`JsonRequestBodyParser::parse()` does not check the `Content-Type` header on the incoming request. It attempts to JSON-decode the body unconditionally:

```php
// All three reach JsonRequestBodyParser::parse() identically:
// Content-Type: application/json → works
// Content-Type: application/x-www-form-urlencoded → 400 (JSON parse fails on form body)
// (no Content-Type) + JSON body → works
```

This means:
- A valid JSON body without `Content-Type` is accepted — liberal input policy.
- A form-encoded body (`name=Alice&age=30`) results in a 400 Bad Request (JSON parse failure), not a 415 Unsupported Media Type.

## If you need 406 or 415 responses

Add a middleware that inspects the `Accept` and `Content-Type` headers before the route handler:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JsonOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $problems,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Enforce JSON-only Accept (optional — most clients send */* or application/json)
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // Enforce JSON Content-Type on state-changing requests
        $method      = strtoupper($request->getMethod());
        $contentType = $request->getHeaderLine('Content-Type');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)
            && $contentType !== ''
            && !str_contains($contentType, 'application/json')
        ) {
            return $this->problems->create($request, 'unsupported-media-type', 'Unsupported Media Type', 415,
                'This API only accepts application/json request bodies.');
        }

        return $handler->handle($request);
    }
}
```

Wire it via `RuntimeApplicationFactory`:

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **Note:** `authMiddleware` is evaluated before routing. Place content-type enforcement here if you want it applied globally.
