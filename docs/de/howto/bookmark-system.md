# Lesezeichen-System

Ermöglicht es Benutzern, Elemente in benannten Sammlungen zu speichern. Das Setzen von Lesezeichen ist idempotent — dasselbe Element zweimal mit einem Lesezeichen zu versehen gibt das vorhandene Lesezeichen ohne Fehler zurück.

## Überblick

Ein Lesezeichen-System umfasst:
- **Lesezeichen hinzufügen** — ein Element in der Sammlung eines Benutzers speichern (idempotent)
- **Lesezeichen entfernen** — ein gespeichertes Lesezeichen löschen (404, wenn nicht gefunden)
- **Lesezeichen auflisten** — alle Lesezeichen eines Benutzers, optional nach Sammlung gefiltert
- **Lesezeichen zählen** — einfacher Badge-Zähler
- **Lesezeichen abrufen** — prüfen, ob ein bestimmtes Element mit einem Lesezeichen versehen ist

## Datenbankschema

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

`UNIQUE (user_id, item_id)` erzwingt ein Lesezeichen pro Benutzer pro Element. Das `collection`-Feld gruppiert Lesezeichen in benannte Kategorien, wobei `'default'` als Fallback gilt.

## Idempotentes Hinzufügen

Vor dem Einfügen auf ein vorhandenes Lesezeichen prüfen. Bei Konflikt (Race Condition) `DatabaseConstraintException` abfangen und den vorhandenen Eintrag zurückgeben:

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // bereits als Lesezeichen gespeichert — kein Fehler
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race Condition — eine andere Anfrage war schneller; vorhandenes Lesezeichen zurückgeben
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

Das Prüfen-dann-Einfügen-Muster behandelt den häufigen Fall effizient. Der `DatabaseConstraintException`-Catch behandelt die Race Condition bei gleichzeitigen Anfragen.

## Sammlungsfilterung

Optionalen `collection`-Query-Parameter zum Filtern von Lesezeichen verwenden:

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

`null` als Sammlung gibt alle Lesezeichen zurück; ein nicht-leerer String filtert auf diese Sammlung.

## Entfernen gibt 204 vs. 404 zurück

- `204 No Content` — Lesezeichen existierte und wurde gelöscht
- `404 Not Found` — Lesezeichen existierte nicht

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` gibt die Anzahl der betroffenen Zeilen zurück — null bedeutet, dass kein Lesezeichen gefunden wurde.

## MySQL-Schema

MySQL erfordert explizite `ENGINE=InnoDB`- und `AUTO_INCREMENT`-Syntax:

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Für MySQL-Integrationstests `SET FOREIGN_KEY_CHECKS = 0` vor dem Löschen von Tabellen ausführen, um FK-Abhängigkeitsreihenfolge-Probleme zu vermeiden.

## MySQL-Integrationstestmuster

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## Sicherheitseigenschaften

| Eigenschaft | Implementierung |
|---|---|
| Ein Lesezeichen pro Benutzer pro Element | `UNIQUE (user_id, item_id)` DB-Constraint |
| Race Condition beim Hinzufügen | `DatabaseConstraintException` abfangen → vorhandenes zurückgeben |
| Benutzerisolation | Alle Abfragen filtern nach `user_id` |
| Nicht vorhandenes entfernen | Gibt 404 zurück (nicht stillschweigend) |

## Routenübersicht

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/users` | Benutzer erstellen |
| `POST` | `/items` | Element erstellen |
| `POST` | `/users/{userId}/bookmarks` | Lesezeichen hinzufügen (idempotent) |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Lesezeichen entfernen (204 oder 404) |
| `GET` | `/users/{userId}/bookmarks` | Lesezeichen auflisten (`?collection=`-Filter) |
| `GET` | `/users/{userId}/bookmarks/count` | Gesamtzahl der Lesezeichen |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Status eines einzelnen Lesezeichens abrufen |
