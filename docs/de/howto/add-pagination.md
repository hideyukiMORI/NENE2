# Paginierung hinzufügen

Diese Anleitung zeigt, wie Sie mit dem `PaginationQueryParser`-Helper aus `Nene2\Http` eine `?limit=` / `?offset=` Paginierung zu einem Collection-Endpoint hinzufügen.

## Voraussetzungen

- Ein funktionierender Collection-Handler (z.B. `ListNotesHandler`).
- Der Handler gibt einen JSON-Envelope mit `items`, `limit` und `offset` zurück.

## Schritt 1 — `PaginationQueryParser::parse()` aufrufen

Ersetzen Sie die manuelle Query-Parameter-Extraktion durch den Parser. Er validiert die Werte und wirft `ValidationException` (→ 422), wenn sie außerhalb des Bereichs liegen.

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // Standard: limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* $output->items mappen */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` ist ein readonly DTO mit zwei Eigenschaften: `limit: int` und `offset: int`.

## Schritt 2 — Limits anpassen (optional)

Übergeben Sie `$defaultLimit` und `$maxLimit`, um die Standardwerte (20 und 100) zu überschreiben:

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| Parameter | Standard | Bedeutung |
|---|---|---|
| `$defaultLimit` | `20` | Wird verwendet, wenn `?limit=` fehlt |
| `$maxLimit` | `100` | Maximal erlaubter Wert; gibt 422 zurück wenn überschritten |

## Schritt 3 — Den 422-Fehler behandeln

`PaginationQueryParser::parse()` wirft `ValidationException` wenn:

- `limit < 1` oder `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` mappt `ValidationException` automatisch auf `422 validation-failed`.
Im Handler ist keine zusätzliche Fehlerbehandlung erforderlich.

**Beispiel 422-Antwort:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## So funktioniert es

`PaginationQueryParser::parse()` liest `getQueryParams()` aus der PSR-7-Anfrage, castet Werte zu `int`, validiert sie und gibt ein `PaginationQuery`-DTO zurück. Nicht-numerische Werte werden zu `0` gecastet (PHP-`(int)`-Cast-Verhalten) und dann durch die `limit < 1`-Prüfung abgefangen.

## Siehe auch

- `src/Example/Note/ListNotesHandler.php` — Referenzimplementierung mit dem Parser
- `src/Example/Tag/ListTagsHandler.php` — zweites Beispiel
- `Nene2\Http\PaginationQuery` — readonly DTO
- `Nene2\Http\PaginationQueryParser` — die Parser-Klasse
