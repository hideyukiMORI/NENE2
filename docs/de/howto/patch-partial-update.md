# How-to: PATCH Teilaktualisierung (JSON Merge Patch)

> **FT-Referenz**: FT326 (`NENE2-FT/patchlog`) — JSON Merge Patch (RFC 7396) Teilaktualisierung: Null-Feld-Reset, unveränderliche Felder-Ablehnung, ETag/If-Match, nur-Eigentümer-Mutation, 42 Tests / 141 Assertions PASS.

Diese Anleitung zeigt, wie ein `PATCH`-Endpunkt nach JSON-Merge-Patch-Semantik implementiert wird: Nur angegebene Felder werden aktualisiert, `null` setzt auf Standard zurück und unveränderliche Felder werden abgelehnt.

## Schema

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',
    version    INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST`  | `/documents` | Erstellen (erfordert `X-User-Id`) |
| `GET`   | `/documents` | Auflisten |
| `GET`   | `/documents/{id}` | Abrufen mit ETag-Header |
| `PATCH` | `/documents/{id}` | Teilaktualisierung (erfordert `X-User-Id`) |
| `DELETE`| `/documents/{id}` | Löschen (nur Eigentümer) |

## Erstellen

```php
POST /documents  X-User-Id: 1
{"title": "Mein Dokument", "body": "Inhalt"}
→ 201  {"id": 1, "owner_id": 1, "title": "Mein Dokument", "status": "draft", "version": 1}

// Kein X-User-Id → 401
// Fehlender Titel → 422
// Leerer Titel  → 422
// body ist optional → Standard ""
```

## GET mit ETag

```php
GET /documents/1
→ 200  ETag: "doc-1-1"
{"id": 1, "title": "Mein Dokument", "version": 1, ...}
```

ETag-Format: `"doc-{id}-{version}"`.

## PATCH — JSON-Merge-Patch-Semantik

```php
// Nur Titel aktualisieren — body unverändert
PATCH /documents/1  X-User-Id: 1
{"title": "Aktualisiert"}
→ 200  {"title": "Aktualisiert", "body": "Inhalt", ...}

// Nur body aktualisieren
PATCH /documents/1  X-User-Id: 1
{"body": "Neuer Inhalt"}
→ 200  {"title": "Aktualisiert", "body": "Neuer Inhalt", ...}

// Leeres {} — kein Vorgang (gültig per RFC 7396 §3)
PATCH /documents/1  X-User-Id: 1
{}
→ 200  (unverändertes Dokument)

// null setzt Feld auf Standard zurück
PATCH /documents/1  X-User-Id: 1
{"status": null}
→ 200  {"status": "draft"}   // auf Standard zurückgesetzt
```

## Unveränderliche Felder — Abgelehnt

Einige Felder dürfen niemals via PATCH geändert werden:

```php
PATCH /documents/1  {"id": 999}         → 422  // unveränderlich
PATCH /documents/1  {"owner_id": 99}    → 422  // unveränderlich
PATCH /documents/1  {"version": 999}    → 422  // unveränderlich
PATCH /documents/1  {"created_at": "…"} → 422  // unveränderlich
```

## Nur-Eigentümer-Autorisierung

```php
// Benutzer 2 versucht, Benutzer 1's Dokument zu patchen → 404 (nicht 403, um Enumeration zu verhindern)
PATCH /documents/1  X-User-Id: 2  {"title": "Gestohlen"}  → 404

// Eigentümer kann immer sein eigenes Dokument patchen
PATCH /documents/1  X-User-Id: 1  {"title": "Meins"}      → 200
```

## ETag / If-Match

```php
// Bedingtes PATCH — 412 wenn Version sich geändert hat
PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Aktualisiert"}
→ 200  // wenn Version noch 1 ist

PATCH /documents/1  X-User-Id: 1  If-Match: "doc-1-1"
{"title": "Veraltet"}
→ 412  // wenn Version jetzt 2 ist
```

## Typ-Validierung

```php
PATCH /documents/1  {"title": 123}   → 422  // int statt string
PATCH /documents/1  {"body": [1,2]}  → 422  // array statt string
```

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Fehlendes Feld gleich wie `null` behandeln | Aufrufer kann ein Feld nicht leeren; `undefined` ≠ `null` in Merge Patch |
| Patchen von `owner_id` erlauben | Eigentümerschaftsübertragung via API ohne Autorisierungsablauf |
| 403 für mandantenübergreifenden Zugriff zurückgeben | Verrät Existenz des Dokuments; stattdessen 404 zurückgeben |
| Gesamtes Dokument bei PATCH ersetzen | Überschreibt Felder, die der Client nicht ändern wollte |
| Unveränderliche Felder stillschweigend akzeptieren (kein Vorgang) | Client glaubt, `id` geändert zu haben; stiller Fehler verursacht Verwirrung |
