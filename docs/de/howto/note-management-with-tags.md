# How-to: Notizverwaltung mit Tags

## Übersicht

Diese Anleitung behandelt den Aufbau einer mit Tags versehenen Notizverwaltungs-API mit NENE2. Funktionen umfassen benutzerbezogene Isolation, Tag-basierte Filterung, Volltext-Schlüsselwortsuche und eigentümerverpflichtetes CRUD.

**Referenzimplementierung**: `../NENE2-FT/notelog/`

---

## Schema-Design

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS note_tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id INTEGER NOT NULL,
    tag     TEXT    NOT NULL,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    UNIQUE (note_id, tag)
);
```

`ON DELETE CASCADE` entfernt Tags automatisch, wenn eine Notiz gelöscht wird.

---

## Routentabelle

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/notes` | Benutzer | Notiz erstellen |
| `GET` | `/notes` | Benutzer | Eigene Notizen auflisten (optional `?tag=` oder `?q=`) |
| `GET` | `/notes/{id}` | Benutzer | Eine Notiz abrufen |
| `PUT` | `/notes/{id}` | Benutzer | Notizfelder aktualisieren |
| `DELETE` | `/notes/{id}` | Benutzer | Notiz löschen |

---

## Tag-Filterung

Nach Tag mit `JOIN` filtern:

```sql
SELECT n.* FROM notes n
JOIN note_tags t ON t.note_id = n.id
WHERE n.user_id = :uid AND t.tag = :tag
ORDER BY n.id DESC
```

---

## Schlüsselwortsuche

Volltext-Suche in Titel und Body mit `LIKE`:

```sql
SELECT * FROM notes
WHERE user_id = :uid AND (title LIKE :kw OR body LIKE :kw)
ORDER BY id DESC
```

Der `:kw`-Platzhalter ist `'%' . $keyword . '%'`. Parametrisierte Abfragen verhindern SQL-Injection.

---

## Tag-Parsen

Tags müssen Arrays von Strings sein; zu Kleinbuchstaben normalisieren:

```php
private function parseTags(mixed $raw): ?array
{
    if (!is_array($raw)) return [];
    $tags = [];
    foreach ($raw as $tag) {
        if (!is_string($tag)) return null;   // Nicht-String ablehnen → 422
        $t = trim($tag);
        if ($t !== '') $tags[] = strtolower($t);
    }
    return $tags;
}
```

---

## IDOR / Eigentümerschaftsmuster

Alle Lese- und Schreiboperationen werden auf `user_id` beschränkt. Bei Lesevorgängen 404 (nicht 403) zurückgeben, um die Offenlegung von Existenz zu vermeiden; bei Schreibvorgängen 403 zurückgeben, damit der Benutzer weiß, dass die Ressource existiert, er aber keine Berechtigung hat:

```php
// Lesen: 404 zur Verhinderung von Informationsoffenlegung
if ((int) $note['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Note not found.');
}

// Schreiben: 403 wenn Ressource existiert, aber nicht eigene
if ((int) $note['user_id'] !== $userId) {
    return 'forbidden';
}
```

---

## Teilupdate (PUT)

`null` für jedes Feld akzeptieren, um "keine Änderung" zu bedeuten:

```php
$title    = isset($body['title']) ? trim((string) $body['title']) : null;
$noteBody = isset($body['body']) ? (string) $body['body'] : null;
$tags     = (isset($body['tags'])) ? $this->parseTags($body['tags']) : null;
```

Im Repository nur Felder aktualisieren, die nicht-null sind.

---

## HTTP-Statuscodes

| Situation | Status |
|-----------|--------|
| Notiz erstellt | 201 |
| Notiz abgerufen / Liste | 200 |
| Notiz aktualisiert / gelöscht | 200 |
| Kein X-User-Id | 400 |
| Leerer Titel | 422 |
| Nicht-String-Tag-Werte | 422 |
| Notiz nicht gefunden (oder IDOR) | 404 |
| Notiz eines anderen aktualisieren/löschen | 403 |
