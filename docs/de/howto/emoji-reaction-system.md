# Ein Emoji-Reaktionssystem mit NENE2 aufbauen

Diese Anleitung führt durch den Aufbau eines Reaktionssystems, bei dem Benutzer auf Beiträge mit Emojis reagieren, mit gruppierten Zählungen und benutzerbasiertem Reaktions-Tracking.

**Field Trial**: FT143  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: UNIQUE(post_id, user_id, emoji)-Constraint, GROUP-BY-Emoji-Zählungen, benutzerbezogenes Reaktions-Tracking, Emoji-Längenvalidierung, MySQL-Integrationstests

---

## Was wir bauen

- `POST /posts` — einen Beitrag erstellen
- `POST /posts/{id}/reactions` — eine Reaktion hinzufügen (Emoji-String, eine pro Emoji pro Benutzer)
- `DELETE /posts/{id}/reactions/{emoji}` — eine Reaktion entfernen (nur eigene)
- `GET /posts/{id}/reactions` — Reaktionszählungen und die Reaktionen des aktuellen Benutzers abrufen

---

## Datenbankschema

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (post_id, user_id, emoji)` — eine Zeile pro Emoji pro Benutzer pro Beitrag. Derselbe Benutzer kann mit verschiedenen Emojis reagieren (👍 und ❤️ = 2 Zeilen). Mehrere Benutzer können dasselbe Emoji verwenden (jeder erhält seine eigene Zeile).

---

## Doppelte Reaktion → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

Der Handler gibt 409 zurück, wenn `addReaction()` `false` zurückgibt. Keine separate Existenzprüfung erforderlich.

---

## Gruppierte Reaktionszählungen mit GROUP BY

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

Sortiert nach Anzahl absteigend (beliebtestes Emoji zuerst), dann alphabetisch als Tiebreaker. Das Ergebnis wird direkt auf ein PHP-`array<string, int>` abgebildet:

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## Reaktionen pro Benutzer (optionaler Akteur)

Der `GET /reactions`-Endpunkt akzeptiert einen optionalen `X-User-Id`-Header. Wenn vorhanden, enthält die Antwort die Liste der Emojis, die der Aufrufer verwendet hat:

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

Das ermöglicht es der UI, anzuzeigen, mit welchen Emojis der aktuelle Benutzer bereits reagiert hat.

---

## Emoji-Validierung

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` zählt Unicode-Codepunkte, nicht Bytes. Ein einzelnes Emoji wie 🧑‍💻 (Person: Technologe) sind 3 Codepunkte; ein 8-Zeichen-Limit deckt die meisten Emoji-Sequenzen ab. Den eigenen Anforderungen entsprechend anpassen.

---

## MySQL-Integrationstests (FT143)

Die Reihenfolge beim MySQL-Teardown ist wichtig:

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

Das MySQL-Schema verwendet `VARCHAR(32)` für Emoji (nicht `TEXT`), damit die Spalte in einem UNIQUE-Key ohne Präfixlänge verwendet werden kann. `VARCHAR(32)` speichert bis zu 32 Zeichen, was alle Emoji-Sequenzen abdeckt.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|---------|-----|
| Doppelte Emoji-Reaktionen erlauben | `UNIQUE (post_id, user_id, emoji)` + `DatabaseConstraintException` abfangen |
| `strlen()` für Emoji-Länge verwenden | `mb_strlen()` verwenden — Emojis sind Multi-Byte-Unicode |
| Veränderliche Zählspalte wird nicht synchron | Aus der `reactions`-Tabelle mit `GROUP BY emoji` zählen |
| Fehlende MySQL-Emoji-Unterstützung | `utf8mb4`-Charset und `VARCHAR` (nicht `CHAR`) für die Emoji-Spalte verwenden |
| `is_array()` auf `fetchAll`-Ergebnis ist immer true | Prüfung weglassen; `fetchAll` gibt bereits `array<int, array<string, mixed>>` zurück |
