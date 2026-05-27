# Anleitung: Threaded-Comments-API

> **FT-Referenz**: FT343 (`NENE2-FT/threadlog`) — Zweistufiges Kommentar-Thread-System mit Tombstone-Löschung (Inhalt ersetzt durch `[deleted]`), Antwort-Tiefenbegrenzung, beitragsbezogene Isolierung und Prävention von Antworten auf gelöschte Kommentare, 14 Tests / 40+ Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Kommentarsystem mit einer Antwortebene gebaut wird: Root-Kommentare können Antworten erhalten, aber Antworten können nicht beantwortet werden (maximale Tiefe = 1). Gelöschte Kommentare werden tombstoniert, um die Thread-Struktur zu erhalten.

## Schema

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- opaker Beitragsbezeichner
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = Root-Kommentar
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = Root-Kommentar; `parent_id IS NOT NULL` = Antwort.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/posts/{postId}/comments` | Root-Kommentar erstellen |
| `GET`  | `/posts/{postId}/comments` | Kommentare mit Antworten auflisten |
| `GET`  | `/posts/{postId}/comments/{id}` | Einzelnen Kommentar abrufen |
| `POST` | `/posts/{postId}/comments/{id}/replies` | Antwort hinzufügen |
| `DELETE` | `/posts/{postId}/comments/{id}` | Soft Delete (Tombstone) |

## Root-Kommentar erstellen

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Great post!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Great post!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// Fehlende Felder
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // Inhalt erforderlich
```

## Kommentare auflisten

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Root comment",
      "replies": [
        {"id": 2, "author": "bob", "content": "My reply", "parent_id": 1}
      ]
    }
  ]
}
```

Kommentare sind auf `post_id` bezogen. Kommentare von `post-1` erscheinen nie in der Liste von `post-2`.

## Einzelnen Kommentar abrufen

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Root comment",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## Antwort hinzufügen (Maximale Tiefe = 1)

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "My reply"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "My reply"
}

// Antwort auf eine Antwort wird abgelehnt (Tiefe würde 2 sein)
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Deep reply"}
→ 409  // Tiefenlimit überschritten

// Antwort auf nicht existierenden Kommentar
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// Antwort auf gelöschten Kommentar
// (Kommentar 1 bereits gelöscht)
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // Kann nicht auf gelöschten Kommentar antworten

// Fehlende Felder → 422
```

### Tiefen-Check-Implementierung

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // Nur Root-Kommentare (parent_id = null) können Antworten haben
    return $row['parent_id'] === null;
}
```

409 zurückgeben, wenn `canReceiveReply()` false zurückgibt.

## Tombstone-Löschung

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// Gelöschter Kommentar erscheint noch in der Liste (Tombstone)
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // Antworten noch sichtbar
    }
  ]
}
```

Tombstoning bewahrt die Thread-Struktur. Antworten bleiben sichtbar, auch nachdem der Elternkommentar gelöscht wurde.

```php
// Bereits gelöschten Kommentar löschen → 404
DELETE /posts/post-1/comments/1  (bereits gelöscht)
→ 404

// Unbekannter Kommentar → 404
DELETE /posts/post-1/comments/999
→ 404
```

### Tombstone-SQL

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- Trifft nur nicht-gelöschte Zeilen
-- 0 Zeilen aktualisiert → 404
```

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Elternkommentar hart löschen | Antworten werden zu Waisen; Thread-Struktur bricht zusammen |
| Unbegrenzte Verschachtelungstiefe erlauben | Tiefe Ketten erzeugen rekursive SQL-Abfragen oder Stack Overflows |
| 404 für Antwort-auf-Gelöschtes zurückgeben | Den Elternzustand zu verstecken verwirrt Clients; 409 mit klarem `detail` ist besser |
| Kein `post_id`-Scope in Abfragen | Kommentare von anderen Beiträgen erscheinen in der Liste |
| Tiefe nur client-seitig prüfen | Angreifer umgeht die Prüfung durch direkte API-Anfragen |
| Autor/Inhalt des gelöschten Kommentars anzeigen | Macht den Zweck der Löschung zunichte; immer tombstonen |
