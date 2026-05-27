# How-to: Kommentar-Thread-API

Diese Anleitung demonstriert ressourcenbezogene Kommentar-Threads mit Paginierung, Nur-Autor-Löschung und Admin-Override.

## Musterübersicht

- Kommentare gehören zu einer Ressource (identifiziert durch eine ganzzahlige ID).
- Jeder authentifizierte Benutzer kann einen Kommentar zu einer beliebigen Ressource posten.
- Kommentare sind öffentlich lesbar (keine Auth für das Auflisten erforderlich).
- Autoren können ihre eigenen Kommentare löschen; Admins können beliebige löschen.
- Paginierung über `limit`- und `offset`-Query-Parameter.

## Schema

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## Paginierungs-Muster

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

Die Antwort enthält `total` (Anzahl aller Kommentare für die Ressource), `limit` und `offset`, sodass Clients Paginierungssteuerelemente aufbauen können:

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## Limit-Begrenzung

Ungültige oder außerhalb des Bereichs liegende limit/offset-Werte werden stillschweigend auf sichere Standardwerte begrenzt:

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

`ctype_digit()` wird verwendet, um ReDoS am Query-String zu vermeiden.

## IDOR: Nur-Autor-Löschung

Nicht-Admin-Benutzer können nur ihre eigenen Kommentare löschen. Der Versuch, den Kommentar eines anderen Benutzers zu löschen, gibt 404 zurück (nicht 403):

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## Ressourcenisolation

Alle Abfragen enthalten `WHERE resource_id = :rid`, damit Kommentare für Ressource 1 nie mit Ressource 2 gemischt werden.

## Validierungsregeln

| Feld | Regel |
|------|-------|
| `X-User-Id` | Erforderlich für POST/DELETE; `ctype_digit`, >0 |
| `body` | Nicht leer, max. 2000 Zeichen |
| `{resourceId}`-Pfad | `ctype_digit`, max. 18 Zeichen, >0; sonst 404 |
| `limit`-Query | Integer 1–100; Standard 20 |
| `offset`-Query | Nicht-negative Ganzzahl; Standard 0 |

## Routen

```
POST   /resources/{resourceId}/comments  Kommentar posten (X-User-Id erforderlich)
GET    /resources/{resourceId}/comments  Kommentare auflisten (paginiert, öffentlich)
DELETE /comments/{id}                   Kommentar löschen (Autor oder Admin)
```

## Siehe auch

- FT211-Quelle: `../NENE2-FT/commentlog/`
- Verwandt: `docs/howto/note-taking.md` (FT202, Notiz-CRUD)
- Verwandt: `docs/howto/leaderboard-ranking.md` (FT206, ressourcenbezogene Daten)
