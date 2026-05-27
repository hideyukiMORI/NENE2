# How-to: API-Versionierung

> **FT-Referenz**: FT346 (`NENE2-FT/versionlog`) — URL-Pfad-Versionierung mit /v1/- und /v2/-Namespaces, veraltetes V1 mit Deprecation/Sunset/Link-Headern, V2 mit angereicherter Antwortstruktur, gemeinsame Datenspeicherung, 16 Tests PASS.

Diese Anleitung zeigt, wie Sie URL-Pfad-Versionierung implementieren: zwei API-Versionen gleichzeitig mit unterschiedlichen Antwortstrukturen betreiben, die alte Version mit HTTP-Headern als veraltet markieren und eine einzige Datenbank über Versionen hinweg teilen.

## Versionsstrategie

| Version | Status | Präfix | Listen-Wrapper |
|---------|-------------|---------|---------------------------|
| V1 | Veraltet | `/v1/` | `{"notes": [...]}` |
| V2 | Aktuell | `/v2/` | `{"data": [...], "meta": {...}}` |

Beide Versionen teilen dieselben Datenbanktabellen. V1-Clients können ihre bestehende Integration weiterhin nutzen, während die Deprecation-Header einen Migrationsstichtag signalisieren.

## Endpunkte

| Methode | V1-Pfad | V2-Pfad | Beschreibung |
|----------|-----------------|-----------------|-----------------|
| `POST` | `/v1/notes` | `/v2/notes` | Notiz erstellen |
| `GET` | `/v1/notes` | `/v2/notes` | Notizen auflisten |
| `GET` | `/v1/notes/{id}` | `/v2/notes/{id}` | Einzelne Notiz abrufen |

## V1-Antwortstruktur

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← Feldname: "content"
  "created_at": "..."
  // Kein "body", kein "tags", kein "updated_at"
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← Wrapper-Schlüssel: "notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## V2-Antwortstruktur

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← Envelope-Schlüssel: "data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← Feldname: "body"
    "tags": ["php", "api"],  // ← Tags hinzugefügt
    "updated_at": "...",     // ← updated_at hinzugefügt
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← Listen-Wrapper: "data"
  "meta": {               // ← Meta-Abschnitt
    "limit": 20,
    "offset": 0
  }
}
```

## V1-Deprecation-Header

Jede V1-Antwort enthält drei Header, die Clients zur Migration auffordern:

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// Jeder V1-Endpunkt fügt hinzu:
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

V2-Antworten enthalten **keinen** dieser Header.

```php
// V1 GET /v1/notes Header:
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// V2 GET /v2/notes Header:
// (kein Deprecation, Sunset oder Link)
```

## Gemeinsamer Speicher — versionsübergreifender Zugriff

Beide Versionen teilen dieselbe `notes`-Tabelle. Eine über V1 erstellte Notiz ist von V2 aus lesbar (und umgekehrt):

```php
// Über V1 erstellen
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// Über V2 lesen — gleicher Datensatz, V2-Struktur
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 nennt es "body", nicht "content"
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

V1-Clients sehen niemals `tags` (nicht in der V1-Antwortstruktur), auch wenn die Notiz Tags aus einem V2-Schreibvorgang hat.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON-Array
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

Die zugrunde liegende Spalte heißt `body`. V1 mappt sie in der Antwort-Transformation auf `content`.

## Implementierung — Antwort-Transformatoren

```php
// V1-Transformator — mappt "body"-Spalte → "content"-Feld, versteckt tags/updated_at
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // Feldumbenennung
            'created_at' => $row['created_at'],
            // Kein "body", "tags", "updated_at"
        ];
    }
}

// V2-Transformator — vollständige Zeile, in "data" eingebettet
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## Routen-Registrierung

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

Beide Registrars werden an `RuntimeApplicationFactory` übergeben — Routen von beiden werden im selben Router registriert.

## Unbekannte Version → 404

```php
GET /v3/notes
→ 404
```

Es gibt keine V3-Route; der Router gibt 404 zurück. Kein "Version nicht unterstützt"-Fehlertyp wird benötigt — 404 ist ausreichend.

## Validierung

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title ist erforderlich

POST /v2/notes  {"body": "no title"}
→ 422  // title ist erforderlich
```

Beide Versionen erfordern `title`. V1 akzeptiert `content` als Body-Feld; V2 akzeptiert `body`.

---

## Was Sie NICHT tun sollten

| Anti-Pattern | Risiko |
|---|---|
| Verschiedene DB-Tabellen pro Version | Versionsübergreifende Lesevorgänge scheitern; Daten migrieren schlecht, wenn Versionen keinen gemeinsamen Zustand haben |
| `Deprecation: true` bei V2 zurückgeben | Clients können nicht unterscheiden, welche Version aktuell ist |
| Kein `Link`-Header mit Nachfolger | Veraltete Clients wissen nicht, wohin sie migrieren sollen |
| DB-Spalte `body` → `content` für V1 umbenennen | Gesamter V2-Code muss sich ändern; Antwort-Transformator zur Umbenennung verwenden, nicht das Schema |
| Sunset-Datum in Tests fest codieren | Tests schlagen nach dem Sunset-Datum fehl; stattdessen eine zukünftige Konstante oder Konfigurationswert verwenden |
| V1 `tags` in der Antwort ausgeben | V1-Clients erhalten ein Feld, das sie nicht verstehen; Strukturverträge brechen stillschweigend |
