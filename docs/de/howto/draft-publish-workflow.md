# How-to: Entwurf → Veröffentlichen → Archivieren-Workflow

> **FT-Referenz**: FT305 (`NENE2-FT/draftlog`) — Artikel-Lebenszyklus-Zustandsmaschine: draft→published→archived Einweg-Übergänge, nur-Autor-Schreibzugriff, Nicht-Autoren sehen nur veröffentlichte Artikel (Entwürfe geben 404 zurück), veröffentlichte Artikel können nicht bearbeitet werden, veröffentlichte Liste schließt Entwürfe und archivierte aus, 20 Tests / 28 Assertions bestanden.

Diese Anleitung zeigt, wie ein Inhaltslebenszyklus implementiert wird, bei dem Artikel als Entwürfe beginnen, veröffentlicht werden, um sichtbar zu werden, und archiviert werden können, um sie aus öffentlichen Auflistungen zu entfernen.

## Schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`CHECK (status IN (...))` stellt sicher, dass nur bekannte Zustände gespeichert werden. Die Zeitstempel `published_at` und `archived_at` protokollieren, wann Übergänge stattfanden.

## Zustandsmaschine

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| Übergang | Vorbedingung | Fehler bei Verletzung |
|---|---|---|
| draft → published | Status muss `'draft'` sein | 422 |
| published → archived | Status muss `'published'` sein | 422 |
| published → draft | ❌ nicht erlaubt | — |
| archived → irgendetwas | ❌ nicht erlaubt | — |

```php
// Veröffentlichungs-Handler
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// Archivierungs-Handler
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/articles` | `X-User-Id` | Artikel erstellen (beginnt als Entwurf) |
| `GET` | `/articles` | — | Nur veröffentlichte Artikel auflisten |
| `GET` | `/articles/{id}` | `X-User-Id` | Artikel abrufen (Sichtbarkeitsprüfung) |
| `PUT` | `/articles/{id}` | `X-User-Id` (Autor) | Entwurf aktualisieren (nur wenn Entwurf) |
| `POST` | `/articles/{id}/publish` | `X-User-Id` (Autor) | Veröffentlichen |
| `POST` | `/articles/{id}/archive` | `X-User-Id` (Autor) | Archivieren |

## Neue Artikel beginnen als Entwurf

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

Der `status` ist bei der Erstellung immer `'draft'`, unabhängig von einem Body-Feld. Der Client kann den Anfangsstatus nicht wählen.

## Sichtbarkeit — Nicht-Autoren sehen nur Veröffentlichtes

```php
// Nicht-Autoren können nur veröffentlichte Artikel sehen
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

Nicht veröffentlichte Artikel (Entwurf oder archiviert) geben Nicht-Autoren 404 zurück. Dies verhindert:
- Dass andere Benutzer unveröffentlichte Entwürfe lesen
- Das Verraten, ob ein Artikel archiviert wurde

## Veröffentlichte Artikel können nicht bearbeitet werden

```php
// Update-Handler — nur Entwürfe sind bearbeitbar
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Nach der Veröffentlichung ist der Artikelinhalt eingefroren. Der Autor muss die Veröffentlichung aufheben (was hier nicht unterstützt wird), um zu bearbeiten — in diesem Design ist das Veröffentlichen ein Einweg-Gate.

## List-Endpunkt — Nur veröffentlicht

```php
// Repository: SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

Der List-Endpunkt filtert auf `status = 'published'`. Entwürfe und archivierte Artikel erscheinen nie in der öffentlichen Auflistung.

## Nur-Autor-Aktionen

Alle Schreibvorgänge (Aktualisieren, Veröffentlichen, Archivieren) prüfen, dass der Akteur der Autor des Artikels ist:

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Status im Create-Body erlauben | Client startet Artikel als `'published'` und umgeht den Review-Workflow |
| 403 für Nicht-Autor-Entwurf-GET zurückgeben | Verrät, dass der Artikel existiert; 404 verwenden, um unveröffentlichten Inhalt zu verbergen |
| Bearbeitung veröffentlichter Artikel erlauben | Ändert Live-Inhalte rückwirkend; verletzt das Leservertrauen |
| archive → published-Übergang erlauben | Archivierte Artikel tauchen unerwartet wieder auf |
| Entwürfe in öffentlicher Auflistung anzeigen | Unveröffentlichter Inhalt wird vor der Fertigstellung preisgegeben |
| Kein `CHECK (status IN (...))` | Direkte DB-Inserts können beliebige Status-Strings setzen |
| Archivierte Artikel geben Nicht-Autoren 200 zurück | Verrät Nicht-Autoren, dass Inhalt existiert hat und archiviert wurde |
