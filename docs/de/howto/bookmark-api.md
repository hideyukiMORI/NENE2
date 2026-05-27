# How-to: Bookmark-API

> **FT-Referenz**: FT295 (`NENE2-FT/bookmarklog`) — Lesezeichen-Verwaltung: UNIQUE(user_id, item_id) verhindert doppelte Lesezeichen, Sammlungsgruppierung mit optionalem Filter, benutzerbezogene Zugriffskontrolle (IDOR-Prävention), 409 bei Duplikat, 22 Tests / 64 Assertions bestanden.

Diese Anleitung zeigt, wie Sie eine Lesezeichen-API erstellen, bei der Benutzer Elemente in benannte Sammlungen speichern können, mit Deduplizierung und benutzerbezogener Zugriffskontrolle.

## Schema

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` stellt sicher, dass jeder Benutzer ein Element genau einmal mit einem Lesezeichen versehen kann. Das `collection`-Feld gruppiert Lesezeichen in benannte Listen (Standard: `'default'`).

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST` | `/users/{userId}/bookmarks` | Lesezeichen hinzufügen |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Lesezeichen entfernen |
| `GET` | `/users/{userId}/bookmarks` | Lesezeichen auflisten (optional nach Sammlung gefiltert) |
| `GET` | `/users/{userId}/bookmarks/count` | Lesezeichen zählen |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Bestimmtes Lesezeichen abrufen |

## Reihenfolge der Routen-Registrierung

`/users/{userId}/bookmarks/count` muss **vor** `/users/{userId}/bookmarks/{itemId}` registriert werden, um zu verhindern, dass `count` als `{itemId}` aufgefangen wird:

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // statisch vor dynamisch
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## Ein Lesezeichen hinzufügen

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // leerer Collection-String → Fallback auf 'default'
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` erfordert `is_int()` — der JSON-String `"5"` wird abgelehnt. Die `UNIQUE`-Constraint in der DB fängt Race Conditions ab; das Repository sollte die Constraint-Verletzung abfangen und 409 zurückgeben.

## Sammlungsfilter bei der Auflistung

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Ohne `?collection=` werden alle Lesezeichen zurückgegeben. Mit `?collection=favorites` wird nur diese Sammlung zurückgegeben. Ein leerer Collection-Query-Parameter wird als "kein Filter" behandelt.

## User-Scoping — IDOR-Prävention

Jeder Endpunkt validiert `userId` gegen die DB, bevor Daten zurückgegeben werden:

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

Ein Aufruf von `/users/999/bookmarks` als anderer Benutzer gibt 404 zurück (nicht die Lesezeichen des anderen Benutzers). Alle Abfragen sind auf die `userId` aus dem Pfad beschränkt.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kein `UNIQUE(user_id, item_id)` | Benutzer kann dasselbe Element mehrfach mit einem Lesezeichen versehen; verwirrende Duplikate |
| Bei doppeltem Lesezeichen 200 zurückgeben | Client kann "hinzugefügt" nicht von "bereits vorhanden" unterscheiden; 409 verwenden |
| `item_id` als String aus dem Body akzeptieren | JSON-Typkonfusion: `"5"` ≠ `5`; `is_int()` verwenden |
| `/{itemId}` vor `/count` registrieren | `GET /users/1/bookmarks/count` löst zu `itemId = "count"` auf (falscher Handler) |
| Keine Benutzer-Existenzprüfung | Nicht existierende userId gibt leere Liste statt 404 zurück |
| Kein User-Scoping in Abfragen | Benutzer A sieht die Lesezeichen von Benutzer B (IDOR) |
| Kein Collection-Standard | Fehlendes `collection`-Feld führt zu Absturz oder `NULL` in der DB |
