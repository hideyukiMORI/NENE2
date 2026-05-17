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

---

## Verfügbare HTTP-Methoden

| Methode | Router-Methode | Typische Verwendung |
|---|---|---|
| GET | `$router->get()` | Eine Ressource lesen |
| POST | `$router->post()` | Eine Ressource erstellen |
| PUT | `$router->put()` | Eine Ressource ersetzen (vollständiges Update) |
| DELETE | `$router->delete()` | Eine Ressource entfernen |

---

## Nächster Schritt

Wenn Ihre Route aus einer Datenbank lesen oder in sie schreiben muss, siehe
[Datenbankendpunkt hinzufügen](./add-database-endpoint.md).
