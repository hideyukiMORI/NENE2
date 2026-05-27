# How-to: Optimistische Nebenläufigkeitskontrolle (Versionsfeld)

> **FT-Referenz**: FT323 (`NENE2-FT/optimisticlog`) — Dokument-API mit Versionsfeld im PUT-Body, 409 bei veralteter Version, Verhinderung verlorener Updates, 18 Tests / 34 Assertions PASS.

Diese Anleitung zeigt, wie optimistische Nebenläufigkeitskontrolle durch Übergabe eines `version`-Feldes im Request-Body implementiert wird, als Alternative zu HTTP-ETag/If-Match-Headern.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/documents` | Erstellen (Version beginnt bei 1) |
| `GET`  | `/documents` | Auflisten |
| `GET`  | `/documents/{id}` | Abrufen mit Version |
| `PUT`  | `/documents/{id}` | Aktualisieren (Version im Body erforderlich) |

## Erstellen

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## Aktualisieren mit Version

Der Client liest die aktuelle `version` und schließt sie in den PUT-Body ein:

```php
// Lesen
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// Aktualisieren mit korrekter Version
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

Die Version wird bei jeder erfolgreichen Aktualisierung inkrementiert.

## Veraltete Version — 409 Conflict

```php
// Alice und Bob lesen beide Version 1
// Alice aktualisiert zuerst → Version wird 2
// Bob versucht mit Version 1 zu aktualisieren → abgelehnt
PUT /documents/1
{"title": "Bobs Bearbeitung", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob liest erneut, erhält Version 2, versucht erneut
PUT /documents/1
{"title": "Bobs Bearbeitung", "version": 2}
→ 200  {"version": 3}
```

## Implementierung

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

Die `WHERE version = ?`-Klausel in der UPDATE-Abfrage ist der atomare Guard gegen gleichzeitige Schreibvorgänge.

## Version vs. ETag

| Aspekt | Versionsfeld (diese Anleitung) | ETag / If-Match (siehe `optimistic-locking-etag.md`) |
|--------|-------------------------------|------------------------------------------------------|
| Protokoll | Body-Feld | HTTP-Header |
| Client-UX | Explizites `"version": N` in JSON | `If-Match: "vN"`-Header |
| 409-Payload | Kann `current_version` zurückgeben | 412 — kein Body-Standard |
| Fehlende Prüfung | 422 (fehlendes `version`) | 428 (fehlendes `If-Match`) |

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| PUT ohne `version`-Feld akzeptieren | Verlorene Updates: letztes Schreiben gewinnt stillschweigend |
| 200 bei veralteter Version zurückgeben | Stille Überschreibung gleichzeitiger Änderungen |
| Version nur im Anwendungscode prüfen (nicht in WHERE-Klausel) | Race Condition zwischen Lesen und Schreiben |
| `current_version` nicht in 409-Antwort einschließen | Client muss neu-GET, um sich zu erholen; für schnelleren Neuversuch einschließen |
