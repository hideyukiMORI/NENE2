# How-to: Emoji-Reaktions-API

> **FT-Referenz**: FT306 (`NENE2-FT/emojilog`) — Emoji-Reaktionen: UNIQUE(post_id, user_id, emoji) erlaubt dasselbe Emoji von mehreren Benutzern, verhindert aber, dass ein Benutzer zweimal mit demselben Emoji reagiert, mb_strlen max 8 Zeichen, urldecode() für Emoji im DELETE-Pfad, user_reactions zeigt die Reaktionen des aktuellen Akteurs, Reaktionen geordnet nach Anzahl DESC, 18 Tests / 28 Assertions bestanden.

Diese Anleitung zeigt, wie ein Emoji-Reaktionssystem implementiert wird, bei dem mehrere Benutzer auf einen Beitrag mit beliebigen Emojis reagieren können, aber jeder Benutzer ein gegebenes Emoji nur einmal pro Beitrag verwenden kann.

## Schema

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

`UNIQUE(post_id, user_id, emoji)` erlaubt:
- Gleiches Emoji von mehreren Benutzern: Alice und Bob können beide mit `👍` reagieren
- Verschiedene Emojis vom gleichen Benutzer: Alice kann sowohl `👍` als auch `❤️` verwenden

Verhindert aber:
- Gleicher Benutzer + gleiches Emoji zweimal: Alice kann `👍` auf demselben Beitrag nicht zweimal verwenden

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id` (optional) | Reaktionszählungen + Reaktionen des Akteurs abrufen |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | Reaktion hinzufügen |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | Reaktion entfernen |

## Reaktion hinzufügen — Strikte Validierung

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- `is_string()`-Prüfung lehnt Nicht-String-Typen ab
- `trim()` vor Leer-Prüfung verhindert nur-Leerzeichen-Emoji
- `mb_strlen()` — nicht `strlen()` — für korrekte Multibyte-Zeichenzählung
- Doppeltes Hinzufügen → 409 Conflict (nicht 422)

## Reaktion entfernen — URL-Decode für Emoji im Pfad

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

Emoji-Zeichen in URL-Pfadsegmenten müssen von Clients URL-kodiert werden. `urldecode()` stellt das ursprüngliche Emoji für die DB-Suche wieder her. Beispiel: `DELETE /posts/1/reactions/%F0%9F%91%8D` → sucht `👍`.

## Reaktionszählungs-Antwort

```php
// Nach Emoji gruppieren, zählen, nach Anzahl DESC ordnen
$counts = $this->repository->getReactionCounts($postId);

// Wenn Akteur angegeben, anzeigen, welche Emojis er verwendet hat
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] geordnet nach Anzahl DESC
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] für den aktuellen Akteur
]);
```

`user_reactions` ist leer, wenn kein `X-User-Id`-Header angegeben wird — dieses Feld zeigt die Reaktionen des aktuellen Betrachters, um Frontends dabei zu helfen, aktive Reaktionen hervorzuheben.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `UNIQUE(post_id, user_id)` (keine Emoji-Spalte) | Ein Benutzer kann pro Beitrag immer nur ein Emoji verwenden |
| `strlen()` für Emoji-Längenprüfung | Multi-Byte-Emoji wie `🎉` (4 Bytes) würden falsch gezählt |
| Kein `urldecode()` für Pfad-Emoji | `👍` als `%F0%9F%91%8D` stimmt nie mit gespeichertem `👍` überein |
| 404 bei doppelter Reaktion zurückgeben | Verbirgt die 409-Semantik — doppelte Reaktionen sind Konflikte, keine fehlenden Ressourcen |
| Kein Emoji-Längenlimit | Beliebig lange Strings werden als Emoji-Spalte gespeichert |
| Leere `user_reactions` bei keinem Akteur, aber Schlüssel trotzdem einschließen | Weglassen oder `[]` zurückgeben — beides ist in Ordnung, aber Verhalten dokumentieren |
| `trim()` nach Leer-Prüfung | Nur-Leerzeichen-`"  "` Emoji passiert als gültig |
