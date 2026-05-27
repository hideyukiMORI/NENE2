# How-to: Collection-API (Benutzerkuratierte Listen)

> **FT-Referenz**: FT299 (`NENE2-FT/collectionlog`) — Benutzerkuratierte Artikel-Collections: is_public/privat Sichtbarkeit (404 für Nicht-Eigentümer bei privaten Collections), `UNIQUE(collection_id, article_id)`-Deduplizierung, Positions-Sortierung, nur-Eigentümer-Schreibzugriff, 20 Tests / 34 Assertions PASS.

Diese Anleitung zeigt, wie eine benutzerkuratierte Collection-API aufgebaut wird, bei der Benutzer benannte Listen erstellen, Artikel dazu hinzufügen und die öffentliche/private Sichtbarkeit steuern.

## Schema

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=privat, 1=öffentlich
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` verhindert, dass derselbe Artikel zweimal in einer Collection erscheint. `position` ermöglicht geordnete Anzeige.

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---------|------|------|--------------|
| `POST` | `/collections` | `X-User-Id` | Collection erstellen |
| `GET` | `/collections/{id}` | `X-User-Id` | Collection abrufen (Sichtbarkeitsprüfung) |
| `PUT` | `/collections/{id}` | `X-User-Id` (Eigentümer) | Name/Sichtbarkeit aktualisieren |
| `DELETE` | `/collections/{id}` | `X-User-Id` (Eigentümer) | Collection löschen |
| `POST` | `/collections/{id}/items` | `X-User-Id` (Eigentümer) | Artikel hinzufügen |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id` (Eigentümer) | Artikel entfernen |

## Sichtbarkeit — 404 für private Collections

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

Nicht-Eigentümer, die versuchen auf eine private Collection zuzugreifen, erhalten 404 — nicht 403. Dies verhindert die Enthüllung der Existenz privater Collections.

## Nur-Eigentümer-Schreibzugriff

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Hinzufügen, Entfernen, Aktualisieren und Löschen erfordern, dass der Akteur der Collection-Eigentümer ist. Anders als bei der Sichtbarkeit geben Schreibzugrifsfehler 403 zurück (die Existenz der Collection ist an diesem Punkt bereits bekannt).

## UNIQUE(collection_id, article_id) — Deduplizierung

Der DB-Constraint verhindert, dass derselbe Artikel zweimal in einer Collection erscheint. Die Anwendung prüft vor dem Einfügen auf Duplikate:

```php
// Repository prüft findItem() vor addItem()
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public als Boolean Integer

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` wird in SQLite als INTEGER (0/1) gespeichert. Beim Lesen: `(bool) $collection['is_public']`. Beim Schreiben: strenge `=== true`-Prüfung verhindert, dass der String `"true"` öffentlichen Zugriff aktiviert.

## Antwortstruktur

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| 403 für privaten Collection-Zugriff zurückgeben | Enthüllt die Existenz der Collection gegenüber Nicht-Eigentümern (Informationsoffenlegung) |
| Beliebigen Benutzern erlauben, Elemente zu beliebigen Collections hinzuzufügen | Nicht-Eigentümer injizieren Inhalte in fremde Collections |
| Kein `UNIQUE(collection_id, article_id)` | Gleicher Artikel zweimal hinzugefügt; verwirrende doppelte Einträge |
| String `"true"` für `is_public` akzeptieren | Typverwirrung: jeder String ist bei losem Vergleich truthy |
| Kein Positionsfeld | Elemente erscheinen immer in Einfüge-Reihenfolge; keine Neuanordnung möglich |
| Collection löschen ohne Eigentumsrecht-Prüfung | Beliebiger Benutzer löscht beliebige Collection |
| `item_count` ohne Einbeziehung von Elementen exponieren | Enthüllt Collection-Größe auch für Nicht-Eigentümer privater Collections |
