# Anleitung: Geplante Artikelveröffentlichung

> **FT-Referenz**: FT330 (`NENE2-FT/pubschedulelog`) — Artikel-Entwurf/Planung/Veröffentlichung/Archiv-Lebenszyklus, nur-Eigentümer-Entwurfszugriff, öffentliche veröffentlichte Artikel, geplanter Veröffentlichungs-Trigger, 34 Tests / 95 Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Artikelverwaltungssystem mit verzögerter Veröffentlichung erstellt wird: Autoren schreiben Entwürfe, planen sie für einen zukünftigen Zeitpunkt, und ein Hintergrundjob (oder API-Aufruf) überführt sie in den veröffentlichten Zustand.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601, NULL, sofern nicht geplant
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Statusübergänge

```
draft ──veröffentlichen──► published ──archivieren──► archived
  │
  └──planen──► scheduled ──(Zeit vergeht)──► published
  │                  │
  │               Planung aufheben
  │                  │
  └──────────────────┘
```

Nur erlaubte Übergänge — ungültige Übergänge geben 409 zurück.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST`  | `/articles` | Entwurf erstellen (`X-User-Id` erforderlich) |
| `GET`   | `/articles/{id}` | Abrufen (Entwurf: nur Eigentümer; veröffentlicht: öffentlich) |
| `PUT`   | `/articles/{id}` | Entwurf aktualisieren (`X-User-Id` erforderlich) |
| `POST`  | `/articles/{id}/publish` | Sofort veröffentlichen |
| `POST`  | `/articles/{id}/schedule` | Für zukünftigen Zeitpunkt planen |
| `POST`  | `/articles/{id}/unschedule` | Zurück zum Entwurf |
| `POST`  | `/articles/{id}/archive` | Veröffentlichten Artikel archivieren |
| `GET`   | `/articles` | Auflisten (mit `?status=`-Filter) |
| `POST`  | `/publish-due` | Geplante Artikel nach publish_at auslösen |

## Entwurf erstellen

```php
POST /articles  X-User-Id: 1
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// Keine Auth → 401
```

## Sichtbarkeitsregeln

```php
// Entwurf: nur Eigentümer
GET /articles/1  X-User-Id: 1  → 200   // Autor sieht eigenen Entwurf
GET /articles/1  X-User-Id: 2  → 404   // anderer Benutzer kann Entwurf nicht sehen
GET /articles/1               → 404   // keine Auth, Entwurf ausgeblendet

// Veröffentlicht: alle
GET /articles/1               → 200   // öffentlich
```

## Veröffentlichen und Archivieren

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// Entwurf kann nicht archiviert werden
POST /articles/1/archive  X-User-Id: 1  → 409
```

## Planen

```php
// Für 1 Stunde ab jetzt planen
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// Vergangener Zeitpunkt → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// Planung aufheben → zurück zum Entwurf
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## Geplante Artikel auslösen

Ein Cron-Job oder Admin-Endpunkt überführt alle geplanten Artikel mit `publish_at <= now`:

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## Artikel auflisten

```php
GET /articles?status=published      → 200  // öffentlich, keine Auth nötig
GET /articles?status=draft  X-User-Id: 1  → 200  // nur eigene Entwürfe
```

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Entwurf nicht authentifizierten Benutzern zeigen | Unveröffentlichte Inhalte lecken |
| Planung in der Vergangenheit erlauben | Artikel würde „sofort" über den Trigger-Job veröffentlicht werden, Überprüfung umgehend |
| Echtzeit-now() im Test für Planungs-Trigger verwenden | Tests werden zeitabhängig; in Tests Force-Insert mit vergangenem `publish_at` verwenden |
| Hard-Delete beim Archivieren | Prüfpfad verloren gehen; Statusfeld verwenden |
| Übergang von archived → published erlauben | Entfernte Inhalte werden zurückgebracht; explizite Neuveröffentlichung erfordern |
