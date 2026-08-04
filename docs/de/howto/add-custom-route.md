# Eine Route hinzufügen

Diese Anleitung zeigt, wie man GET- und POST-Routen mit Pfadparametern zu einer NENE2-Anwendung hinzufügt.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung. Falls nicht, beginnen Sie mit dem [Tutorial](../tutorial/first-api.md).

---

## Eine einfache GET-Route hinzufügen

Routen werden über `routeRegistrars` registriert — ein Array von Funktionen, die jeweils den Router empfangen und Routen darauf registrieren.

```php
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['items' => [], 'count' => 0]);
            });
        },
    ],
))->create();
```

In Express wäre das `app.get('/items', (req, res) => res.json(...))`. Das Muster ist identisch — Route, Handler, Response.

---

## Einen Pfadparameter hinzufügen

Verwenden Sie die `{name}`-Syntax im Routenpfad. Im Handler lesen Sie alle Pfadparameter aus dem `Router::PARAMETERS_ATTRIBUTE`-Request-Attribut — sie werden als benanntes Array gespeichert, nicht als einzelne Attribute.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // Pfadparameter befinden sich in einem einzigen Array-Attribut — nicht in einzelnen Attributen.
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **Häufiger Fehler**: `$req->getAttribute('id')` gibt immer `null` zurück.
> Verwenden Sie immer `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']`.

In Express ist das `req.params.id`. In FastAPI ist es ein typisiertes Funktionsargument. In NENE2 ist es ein explizites Array-Lesen — ausführlicher, aber unmöglich mit Query-String-Parametern zu verwechseln.

### Mehrere Parameter

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## Einen Query-String-Parameter hinzufügen

Query-String-Parameter werden aus dem geparsten Query-Array gelesen, nicht aus dem Routenmuster.

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

Dies entspricht `req.query.limit` in Express oder `request.query_params['limit']` in FastAPI.

---

## Eine POST-Route hinzufügen

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // 422 Validation Failed zurückgeben — für das vollständige Validierungsmuster mit
        // ValidationException, siehe docs/development/endpoint-scaffold.md.
        return $json->create(['error' => 'name is required'], 422);
    }

    // In einem echten Endpoint würden Sie hier in die Datenbank speichern.
    return $json->create(['name' => $name], 201);
});
```

> Für Produktions-Endpoints verwenden Sie `ValidationException` und das Domain-Layer-Pattern
> statt Inline-Validierung. Siehe [Datenbankendpunkt hinzufügen](./add-database-endpoint.md).

---

## Mehrere Routen in einem Registrar

Sie können beliebig viele Routen innerhalb einer einzelnen Registrar-Funktion registrieren:

```php
routeRegistrars: [
    static function (Router $router) use ($json): void {
        $router->get('/items',         /* handler */);
        $router->get('/items/{id}',    /* handler */);
        $router->post('/items',        /* handler */);
        $router->put('/items/{id}',    /* handler */);
        $router->delete('/items/{id}', /* handler */);
    },
],
```

Oder teilen Sie auf mehrere Registrar-Funktionen auf, wenn die Routenliste lang wird.

> **Reihenfolge der Routenregistrierung**: Der Router matcht in der **Registrierungsreihenfolge**.
> Registrieren Sie **statische Routen immer vor parametrisierten**, wenn beide dasselbe Präfix teilen.
> Wenn Sie zuerst `GET /items/{id}` und danach `GET /items/summary` registrieren, matcht eine Anfrage
> an `/items/summary` die `{id}`-Route mit `id = "summary"` — das ergibt einen verwirrenden
> domänenspezifischen 404 statt eines Routing-Fehlers.
>
> ```php
> // FALSCH — /items/summary matcht {id} mit id="summary"
> $router->get('/items/{id}',    $this->show(...));
> $router->get('/items/summary', $this->summary(...)); // wird nie erreicht
>
> // RICHTIG — statisches Segment zuerst gematcht
> $router->get('/items/summary', $this->summary(...));
> $router->get('/items/{id}',    $this->show(...));
> ```

---

## Aktions-Endpunkte (Nicht-CRUD-Operationen)

Manche Operationen passen nicht in die übliche CRUD-Form — archivieren, veröffentlichen, genehmigen,
wiederherstellen. Verwenden Sie dafür `POST /resource/{id}/action`. Die Antwort ist `200 OK` mit der
aktualisierten Ressource im Body.

```php
$router->post('/items/{id}/archive', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});

$router->post('/items/{id}/restore', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});
```

> **Registrierungsreihenfolge**: Aktions-Routen (`/items/{id}/archive`) teilen das `{id}`-Segment mit
> den Show-/Update-Routen — sie funktionieren dennoch korrekt, weil der Aktionspfad nach `{id}` ein
> zusätzliches statisches Segment besitzt und damit unabhängig von der Registrierungsreihenfolge
> eindeutig ist.

### Aktions-Endpunkte mit optionalem Body

Manche Aktionen akzeptieren einen optionalen JSON-Body (z. B. eine `reject`-Aktion mit optionalem
`reason`). `JsonRequestBodyParser::parse()` wirft bei leerem Body einen 400 — prüfen Sie daher auf
einen leeren Body, bevor Sie den Parser aufrufen:

```php
$router->post('/items/{id}/reject', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $raw    = (string) $req->getBody();
    $reason = null;

    if ($raw !== '') {
        $body   = JsonRequestBodyParser::parse($req);
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
    }

    $item = $repo->reject($id, $reason, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item));
});
```

---

## Verfügbare HTTP-Methoden

| Methode | Router-Methode | Typische Verwendung |
|---|---|---|
| GET | `$router->get()` | Eine Ressource lesen |
| POST | `$router->post()` | Eine Ressource erstellen oder eine Aktion auslösen |
| PUT | `$router->put()` | Eine Ressource ersetzen (vollständiges Update) |
| PATCH | `$router->patch()` | Teilweises Update |
| DELETE | `$router->delete()` | Eine Ressource entfernen |

---

## Reservierte Framework-Pfade

Die folgenden Pfade werden von `RuntimeApplicationFactory` registriert, **bevor** Ihre
Route-Registrars laufen. Von Nutzern registrierte Routen für diese Pfade matchen nie, weil die
Routen des Frameworks zuerst geprüft werden.

| Pfad | Methode | Beschreibung |
|---|---|---|
| `/` | GET | Smoke-Endpunkt des Frameworks (Name, Beschreibung, Status) |
| `/health` | GET | Health-Check |
| `/machine/health` | GET | Health-Check für Maschinen-Clients (erfordert API-Key) |
| `/examples/ping` | GET | Beispiel-Ping |
| `/examples/protected` | GET | Beispiel für einen geschützten Endpunkt (bei konfiguriertem JWT) |

Wenn Ihre Anwendung eine Startseite benötigt, verwenden Sie einen anderen Pfad (z. B. `/welcome`,
`/home`) oder liefern Sie das HTML über die Smoke-Antwort des Frameworks aus, indem Sie einen
Health-Check registrieren.

---

## 204 No Content zurückgeben (DELETE-Endpunkte)

Verwenden Sie `JsonResponseFactory::createEmpty()`, um eine 204-Antwort ohne Body zurückzugeben:

```php
private function delete(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $id     = (int) ($params['id'] ?? 0);
    $this->repository->delete($id); // wirft NotFoundException, wenn nicht gefunden
    return $this->json->createEmpty(204);
}
```

> **Warum nicht `create([], 204)`?** Ein leeres Array erzeugt einen JSON-Body `{}`.
> `createEmpty()` liefert eine wirklich body-lose Antwort, was für 204 No Content korrekt ist.

---

## Nächster Schritt

Wenn Ihre Route aus einer Datenbank lesen oder in sie schreiben muss, siehe
[Datenbankendpunkt hinzufügen](./add-database-endpoint.md).
