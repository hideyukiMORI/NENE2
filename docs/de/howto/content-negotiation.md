# Content Negotiation

NENE2 ist ein JSON-first-Framework. Es implementiert keine Content-Negotiation — alle Antworten verwenden `application/json` (oder `application/problem+json` für Fehler), unabhängig davon, was der Client im `Accept`-Header sendet.

## Was NENE2 tut

| Client sendet | Server gibt zurück |
|---------------|-------------------|
| Kein `Accept`-Header | `application/json; charset=utf-8` |
| `Accept: application/json` | `application/json; charset=utf-8` |
| `Accept: */*` | `application/json; charset=utf-8` |
| `Accept: text/html` | `application/json; charset=utf-8` |
| `Accept: application/xml` | `application/json; charset=utf-8` |
| `Accept: text/html;q=1.0, application/json;q=0.9` | `application/json; charset=utf-8` |

**NENE2 gibt niemals `406 Not Acceptable` zurück.** RFC 7231 §6.5.6 besagt, dass der Server SOLLTE 406 zurückgeben, wenn kein akzeptabler Typ verfügbar ist, aber dies ist ein SHOULD (nicht MUST). Für einen JSON-only-API-Server ist es die einfachste und gebräuchlichste Wahl, immer JSON zurückzugeben.

Fehlerantworten verwenden `application/problem+json` (RFC 9457) unabhängig von `Accept`:

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

## Request-Body Content-Type

`JsonRequestBodyParser::parse()` prüft den `Content-Type`-Header der eingehenden Anfrage nicht. Es versucht, den Body bedingungslos JSON-zu-dekodieren:

```php
// Alle drei erreichen JsonRequestBodyParser::parse() identisch:
// Content-Type: application/json → funktioniert
// Content-Type: application/x-www-form-urlencoded → 400 (JSON-Parse schlägt bei Formular-Body fehl)
// (kein Content-Type) + JSON-Body → funktioniert
```

Das bedeutet:
- Ein gültiger JSON-Body ohne `Content-Type` wird akzeptiert — liberale Eingabe-Policy.
- Ein form-kodierter Body (`name=Alice&age=30`) führt zu einem 400 Bad Request (JSON-Parse-Fehler), nicht zu 415 Unsupported Media Type.

## Wenn Sie 406- oder 415-Antworten benötigen

Eine Middleware hinzufügen, die die `Accept`- und `Content-Type`-Header vor dem Route-Handler inspiziert:

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
        // Nur-JSON-Accept erzwingen (optional — die meisten Clients senden */* oder application/json)
        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && $accept !== '*/*' && !str_contains($accept, 'application/json')) {
            return $this->problems->create($request, 'not-acceptable', 'Not Acceptable', 406,
                'This API only produces application/json.');
        }

        // JSON Content-Type bei zustandsändernden Anfragen erzwingen
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

Über `RuntimeApplicationFactory` einbinden:

```php
new RuntimeApplicationFactory(
    ...,
    authMiddleware: new JsonOnlyMiddleware($problems),
);
```

> **Hinweis:** `authMiddleware` wird vor dem Routing ausgewertet. Content-Type-Erzwingung hier platzieren, wenn sie global angewendet werden soll.
