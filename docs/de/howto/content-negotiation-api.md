# How-to: Content Negotiation — JSON API

> **FT-Referenz**: FT301 (`NENE2-FT/contentlog`) — JSON-API-Content-Negotiation: gibt immer `application/json` zurück unabhängig vom `Accept`-Header, `application/problem+json` für Fehler (404/422/405), 415 bei Nicht-JSON-`Content-Type` für POST, 16 Tests / 28 Assertions PASS.

Diese Anleitung behandelt, wie NENE2's Runtime HTTP-Content-Negotiation für JSON-APIs handhabt — welche `Accept`-Header-Werte akzeptiert werden, wann `Content-Type` relevant ist und wie Fehlerantworten `application/problem+json` verwenden.

## Immer JSON — Accept-Header ignorieren

NENE2 JSON-APIs geben `application/json` für Erfolgsantworten zurück, unabhängig vom `Accept`-Header des Clients:

| Gesendeter Accept-Header | Antwort Content-Type |
|--------------------------|----------------------|
| _(keiner)_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

Dies ist für reine API-Dienste beabsichtigt: Der Server ist ein nur-API-Endpunkt, kein Content-Negotiation-Mehrformat-Server. Clients, die `Accept: text/html` senden, erhalten trotzdem JSON.

## Fehlerantworten — application/problem+json

Fehlerantworten verwenden `application/problem+json` (RFC 9457) unabhängig vom `Accept`-Header:

| Szenario | Status | Content-Type |
|----------|--------|--------------|
| Route nicht gefunden | 404 | `application/problem+json` |
| Methode nicht erlaubt | 405 | `application/problem+json` |
| Validierungsfehler | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory erzeugt immer application/problem+json
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

Clients können Fehler entweder am HTTP-Statuscode oder am `Content-Type: application/problem+json`-Header erkennen.

## Request Content-Type — POST-Bodies

Für `POST`-Anfragen mit einem JSON-Body verwendet NENE2 `JsonRequestBodyParser::parse()`:

```php
$body = JsonRequestBodyParser::parse($request);
```

Wenn die Anfrage einen expliziten `Content-Type: text/plain` oder ähnlichen Nicht-JSON-Typ hat, gibt der Parser möglicherweise ein leeres Array zurück. Wenn der Body jedoch gültiges JSON ohne `Content-Type`-Header ist, wird er akzeptiert:

```
POST /articles (kein Content-Type, JSON-Body) → 201 Created ✅
POST /articles (Content-Type: text/plain) → 415 Unsupported Media Type ✅
```

## Validierung — Pflichtfelder

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

Nach `trim()` wird ein leerer String genauso behandelt wie ein fehlendes Feld. Der Validierungsfehler gibt ein strukturiertes `errors`-Array mit `field`-, `code`- und `message`-Schlüsseln zurück — standardmäßige RFC-9457-Erweiterung.

## Antwortstruktur

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## Routen-Registrierung

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles` (Liste) wird vor `GET /articles/{id}` (Einzeln) registriert — obwohl in diesem Fall beide GET mit unterschiedlichen Pfaden sind, entsteht kein Erfassungskonflikt. Die Listen-Route verwendet einen statischen Pfad; die Einzeln-Route verwendet dynamische `{id}`-Erfassung.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| 406 für nicht unterstützte `Accept`-Header zurückgeben | Nur-API-Dienste sollten JSON an alle Clients liefern, nicht ablehnen |
| `text/json` statt `application/json` verwenden | Nicht-standardmäßiger MIME-Typ; einige Clients erkennen ihn nicht |
| Reines `application/json` für Fehlerantworten zurückgeben | Clients können Fehler nicht von Erfolgen am Content-Type unterscheiden; `application/problem+json` verwenden |
| Validierungsfehler `errors`-Array weglassen | Clients können keine feldspezifischen Fehlermeldungen an Benutzer anzeigen |
| `Content-Type: text/plain` für JSON-Bodies akzeptieren | Mehrdeutige Eingabe; explizit angeben, welche Content-Types akzeptiert werden |
| Trimmen nach der Validierung | `trim()` muss vor der leeren-String-Prüfung kommen; `" "` würde bestehen, wenn Sie vor dem Trimmen prüfen |
