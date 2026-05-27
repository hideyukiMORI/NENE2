# How-to: Geplante Artikel-Veröffentlichung

> **FT-Referenz**: FT330 (`NENE2-FT/pubschedulelog`) — Artikel-Entwurf/Zeitplan/Veröffentlichung/Archiv-Lebenszyklus, nur-Eigentümer-Entwurfszugriff, öffentlich veröffentlichte Artikel, geplanter Veröffentlichungsauslöser, 34 Tests / 95 Assertions bestanden.

Dieses Handbuch zeigt, wie man ein Artikelverwaltungssystem mit verzögerter Veröffentlichung aufbaut: Autoren schreiben Entwürfe, planen sie für einen zukünftigen Zeitpunkt, und ein Hintergrundjob (oder API-Aufruf) überführt sie in veröffentlicht.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601, NULL wenn nicht geplant
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## Status-Übergänge

```
draft ──veröffentlichen──► published ──archivieren──► archived
  │
  └──planen──► scheduled ──(Zeit vergeht)──► published
  │               │
  │           unplanen
  │               │
  └───────────────┘
```

Nur erlaubte Übergänge — ungültige Übergänge geben 409 zurück.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `POST`  | `/articles` | Entwurf erstellen (`X-User-Id` erforderlich) |
| `GET`   | `/articles/{id}` | Abrufen (Entwurf: nur Eigentümer; veröffentlicht: öffentlich) |
| `PUT`   | `/articles/{id}` | Entwurf aktualisieren (`X-User-Id` erforderlich) |
| `POST`  | `/articles/{id}/publish` | Sofort veröffentlichen |
| `POST`  | `/articles/{id}/schedule` | Für zukünftigen Zeitpunkt planen |
| `POST`  | `/articles/{id}/unschedule` | Zurück zu Entwurf |
| `POST`  | `/articles/{id}/archive` | Veröffentlichten Artikel archivieren |
| `GET`   | `/articles` | Auflisten (mit `?status=`-Filter) |
| `POST`  | `/publish-due` | Geplante Artikel mit publish_at <= jetzt auslösen |

## Entwurf erstellen

```php
POST /articles  X-User-Id: 1
{"title": "Hallo", "body": "Welt"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// Keine Auth → 401
```

## Sichtbarkeitsregeln

```php
// Entwurf: nur Eigentümer
GET /articles/1  X-User-Id: 1  → 200   // Autor sieht eigenen Entwurf
GET /articles/1  X-User-Id: 2  → 404   // anderer Benutzer kann Entwurf nicht sehen
GET /articles/1               → 404   // keine Auth, Entwurf versteckt

// Veröffentlicht: alle
GET /articles/1               → 200   // öffentlich
```

## Veröffentlichen & Archivieren

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// Kann Entwurf nicht archivieren
POST /articles/1/archive  X-User-Id: 1  → 409
```

## Planen

```php
// Für 1 Stunde ab jetzt planen
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// Vergangene Zeit → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// Unplanen → zurück zu Entwurf
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## Geplante Artikel auslösen

Ein Cron-Job oder Admin-Endpunkt überführt alle geplanten Artikel mit `publish_at <= jetzt`:

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

## Was man NICHT tun sollte

| Anti-Pattern | Risiko |
|---|---|
| Entwurf für unauthentifizierten Benutzer anzeigen | Unveröffentlichte Inhalte lecken |
| Planung in der Vergangenheit erlauben | Artikel würde "sofort" über den Auslöser-Job veröffentlicht, ohne Überprüfung zu umgehen |
| Wanduhr now() im Test für Planungsauslöser verwenden | Tests werden zeitabhängig; vergangenes `publish_at` im Test per Force-Insert verwenden |
| Hartes Löschen beim Archivieren | Prüfungspfad verloren gehen; Status-Feld verwenden |
| Übergang von archiviert → veröffentlicht erlauben | Bringt entfernte Inhalte zurück; explizites Neu-Veröffentlichen erforderlich |
