# Anleitung: Soft Delete, Papierkorb & Wiederherstellungs-API

> **FT-Referenz**: FT340 (`NENE2-FT/softlog`) — Notizen-API mit Soft Delete (deleted_at), Papierkorb-Ansicht, Wiederherstellen, dauerhaftem Hard Delete, Massen-Bereinigung, angeheftet-zuerst-Sortierung und ATK Cracker-Mindset-Angriffsbewertung, 26 Tests / 60+ Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein zweistufiger Lösch-Lebenszyklus implementiert wird: Elemente werden zunächst soft-gelöscht (in den Papierkorb verschoben) und können wiederhergestellt werden, dann dauerhaft über explizites Hard Delete oder Massen-Bereinigung gelöscht.

## Schema

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    is_pinned  INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,               -- NULL = aktiv; ISO 8601 wenn soft-gelöscht
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

`deleted_at IS NULL` = aktiv; `deleted_at IS NOT NULL` = soft-gelöscht (im Papierkorb).

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/notes` | Notiz erstellen |
| `GET`  | `/notes` | Aktive Notizen auflisten (angeheftete zuerst) |
| `GET`  | `/notes/{id}` | Aktive Notiz abrufen |
| `PUT`  | `/notes/{id}` | Aktive Notiz aktualisieren |
| `DELETE` | `/notes/{id}` | Soft Delete (→ Papierkorb) |
| `GET`  | `/notes/trash` | Papierkorb-Notizen auflisten |
| `POST` | `/notes/{id}/restore` | Aus Papierkorb wiederherstellen |
| `DELETE` | `/notes/{id}/permanent` | Hard Delete (dauerhaft) |
| `POST` | `/notes/trash/purge` | Gesamten Papierkorb bereinigen |

## Notiz erstellen

```php
POST /notes
{"title": "My Note", "body": "Content", "is_pinned": false}
→ 201
{
  "id": 1,
  "title": "My Note",
  "body": "Content",
  "is_pinned": false,
  "deleted_at": null,
  "created_at": "..."
}

POST /notes  {"body": "No title"}  → 422  // title erforderlich
```

## Aktive Notizen auflisten (angeheftete zuerst)

```php
GET /notes
→ 200
{
  "total": 3,
  "items": [
    {"id": 2, "title": "Pinned", "is_pinned": true, ...},
    {"id": 1, "title": "Normal A", ...},
    {"id": 3, "title": "Normal B", ...}
  ]
}
```

```sql
SELECT * FROM notes WHERE deleted_at IS NULL
ORDER BY is_pinned DESC, created_at DESC
```

Soft-gelöschte Notizen werden nie in der aktiven Liste zurückgegeben.

## Notiz abrufen

```php
GET /notes/1
→ 200  {"id": 1, "title": "My Note", ...}

// Soft-gelöscht oder unbekannt → gleiches 404
GET /notes/9999    → 404
GET /notes/1 (nach DELETE /notes/1)  → 404
```

## Notiz aktualisieren

```php
PUT /notes/1
{"title": "Updated", "body": "New body", "is_pinned": true}
→ 200  {"title": "Updated", "is_pinned": true, ...}

// Soft-gelöschte Notiz ist nicht aktualisierbar
PUT /notes/1  (nach DELETE /notes/1)  → 404
```

## Soft Delete

```php
DELETE /notes/1
→ 204  (kein Body)

// Notiz verschwindet aus GET /notes und GET /notes/1
// Erscheint aber in GET /notes/trash

DELETE /notes/9999  → 404  // nicht gefunden
```

## Papierkorb-Ansicht

```php
GET /notes/trash
→ 200
{
  "total": 1,
  "items": [
    {"id": 1, "title": "Gone", "deleted_at": "2026-05-27T10:00:00Z", ...}
  ]
}

// Aktive Notizen sind NICHT im Papierkorb
```

`deleted_at` ist für alle Papierkorb-Elemente nicht null.

## Wiederherstellen

```php
POST /notes/1/restore
→ 200  {"id": 1, "title": "Restore Me", "deleted_at": null, ...}

// Wiederhergestellte Notiz erscheint wieder in GET /notes
// POST /notes/9999/restore  → 404
```

## Hard Delete (dauerhaft)

```php
DELETE /notes/1/permanent
→ 204  (kein Body; Notiz ist aus DB weg)

// Auch aus Papierkorb weg
// DELETE /notes/9999/permanent  → 404
```

## Papierkorb bereinigen

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// Leerer Papierkorb
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` gibt `DELETE FROM notes WHERE deleted_at IS NOT NULL` aus und gibt die Zeilenanzahl zurück.

---

## ATK Assessment — Cracker-Mindset-Angrifftest

### ATK-01 — Hard Delete ohne vorheriges Soft Delete 🚫 BLOCKED

**Angriff**: Angreifer ruft `DELETE /notes/1/permanent` auf einer aktiven (noch nicht soft-gelöschten) Notiz auf.
**Ergebnis**: BLOCKED — `DELETE /notes/{id}/permanent` prüft `deleted_at IS NOT NULL` vor dem Fortfahren. Aktive Notizen geben 404 am dauerhaft-löschen-Endpunkt zurück; nur Papierkorb-Elemente können hart gelöscht werden.

---

### ATK-02 — Auf soft-gelöschte Notiz via direktem GET zugreifen ✅ SAFE

**Angriff**: Angreifer weiß, dass Notiz ID 5 soft-gelöscht wurde und ruft `GET /notes/5` auf, um geschützte Inhalte zu lesen.
**Ergebnis**: SAFE — `GET /notes/{id}` fragt `WHERE id = ? AND deleted_at IS NULL` ab. Soft-gelöschte Notizen geben 404 identisch zu unbekannten Notizen zurück — kein Existenzhinweis.

---

### ATK-03 — Papierkorb ohne Auth bereinigen (Massenzerstörung) ⚠️ EXPOSED

**Angriff**: Jeder Client ruft `POST /notes/trash/purge` auf, um alle Papierkorb-Notizen aller Benutzer dauerhaft zu zerstören.
**Ergebnis**: EXPOSED — Keine Authentifizierungsprüfung für `POST /notes/trash/purge`. Ohne Pro-Benutzer-Scoping kann ein nicht-authentifizierter Client irreversibel alle Papierkorb-Daten aller Benutzer löschen. Abhilfe: Authentifizierung erfordern; Bereinigung auf den Papierkorb des authentifizierten Benutzers beschränken; Admin-Rolle für globale Bereinigung erfordern.

---

### ATK-04 — Doppeltes Soft Delete korrumpiert deleted_at ✅ SAFE

**Angriff**: Angreifer sendet `DELETE /notes/1` zweimal, in der Hoffnung, dass der zweite Aufruf `deleted_at` auf einen späteren Zeitstempel zurücksetzt.
**Ergebnis**: SAFE — Das erste Löschen setzt `deleted_at`. Das zweite Löschen findet `deleted_at IS NULL = false`, sodass der Lookup 0 Zeilen zurückgibt → 404. Der Zeitstempel wird nicht geändert.

---

### ATK-05 — Aktive Notiz wiederherstellen (Zustand korrumpieren) 🚫 BLOCKED

**Angriff**: Angreifer ruft `POST /notes/1/restore` auf einer aktiven (nicht-gelöschten) Notiz auf, um `deleted_at = null` bedingungslos zu erzwingen.
**Ergebnis**: BLOCKED — `restore` fragt `WHERE id = ? AND deleted_at IS NOT NULL` ab. Aktive Notizen passen nicht → 404. Idempotent: Eine bereits aktive Notiz wiederherzustellen ist ein No-op 404.

---

### ATK-06 — SQL-Injection via Titel beim Erstellen ✅ SAFE

**Angriff**: Angreifer reicht `{"title": "'; DROP TABLE notes; --"}` ein, um die Datenbank zu korrumpieren.
**Ergebnis**: SAFE — Alle Schreibvorgänge verwenden parametrisierte Statements. Titel wird als Literal-String gespeichert.

---

### ATK-07 — Notiz-ID-Überlauf zum Umgehen der Validierung 🚫 BLOCKED

**Angriff**: Angreifer sendet `GET /notes/99999999999999999999` (20-stellig), um PHP-Integer zu überlaufen und unbeabsichtigte IDs zu erreichen.
**Ergebnis**: BLOCKED — Notiz-IDs werden mit `ctype_digit` + `strlen <= 18` vor der Konvertierung validiert. Überlaufwerte → 422.

---

### ATK-08 — Gelöschte Notiz aktualisieren (Ghost-Schreiben) 🚫 BLOCKED

**Angriff**: Angreifer hat einen veralteten Session-Verweis auf eine gelöschte Notiz und reicht PUT ein, um sie zu ändern.
**Ergebnis**: BLOCKED — `PUT /notes/{id}` fragt `WHERE id = ? AND deleted_at IS NULL` ab. Soft-gelöschte Notizen scheitern an dieser Prüfung → 404. Die Aktualisierung wird abgelehnt.

---

### ATK-09 — Race: Wiederherstellen, dann sofort Bereinigen 🚫 BLOCKED

**Angriff**: Angreifer lässt `POST /notes/1/restore` und `POST /notes/trash/purge` gleichzeitig laufen, um eine Notiz während der Wiederherstellung zu zerstören.
**Ergebnis**: BLOCKED — Jede Operation ist eine einzelne atomare DB-Transaktion. Die Bereinigung gibt `DELETE WHERE deleted_at IS NOT NULL` aus; die Wiederherstellung setzt `deleted_at = NULL`. Einer gewinnt und die Notiz endet in einem konsistenten Zustand.

---

### ATK-10 — Gleichzeitiges Soft Delete hinterlässt Waise ✅ SAFE

**Angriff**: Zwei Anfragen rufen gleichzeitig `DELETE /notes/1` auf. Beide prüfen `deleted_at IS NULL`, sehen beide null und versuchen beide `deleted_at` zu setzen.
**Ergebnis**: SAFE — Das erste Update gelingt. Das zweite findet `deleted_at IS NOT NULL` (oder 0 aktualisierte Zeilen) → 404. SQLite serialisiert Schreibvorgänge; der zweite Aufruf ist auf DB-Ebene idempotent.

---

### ATK-11 — Titel zu lang (Speichermissbrauch) ⚠️ EXPOSED

**Angriff**: Angreifer reicht einen 10 MB-Titel-String ein, um den Datenbankspeicher zu erschöpfen.
**Ergebnis**: EXPOSED — Keine Maximallänge wird für `title` oder `body` durchgesetzt. Abhilfe: `MAX_TITLE_LENGTH` (z. B. 500 Zeichen) und `MAX_BODY_LENGTH` (z. B. 100.000 Zeichen) hinzufügen und 422 zurückgeben wenn überschritten. Request-Size-Middleware bietet einen sekundären Schutz.

---

### ATK-12 — Angeheftet-Überflutung (Viele angeheftete Notizen) ⚠️ EXPOSED

**Angriff**: Angreifer erstellt tausende angehefteter Notizen, um alle echten Notizen aus der aktiven Liste nach unten zu verdrängen.
**Ergebnis**: EXPOSED — Kein Limit für die Anzahl angehefteter Notizen. Jede Notiz kann mit `is_pinned: true` erstellt werden. Abhilfe: Maximale Anzahl angehefteter Notizen pro Benutzer begrenzen (z. B. 10); 422 zurückgeben wenn überschritten.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | Hard Delete ohne Soft Delete | 🚫 BLOCKED |
| ATK-02 | Auf soft-gelöschte via GET zugreifen | ✅ SAFE |
| ATK-03 | Papierkorb ohne Auth bereinigen | ⚠️ EXPOSED |
| ATK-04 | Doppeltes Soft Delete | ✅ SAFE |
| ATK-05 | Aktive Notiz wiederherstellen | 🚫 BLOCKED |
| ATK-06 | SQL-Injection via Titel | ✅ SAFE |
| ATK-07 | Notiz-ID-Überlauf | 🚫 BLOCKED |
| ATK-08 | Soft-gelöschte Notiz aktualisieren | 🚫 BLOCKED |
| ATK-09 | Race: Wiederherstellen + Bereinigen | 🚫 BLOCKED |
| ATK-10 | Gleichzeitiges Soft Delete | ✅ SAFE |
| ATK-11 | Titel zu lang | ⚠️ EXPOSED |
| ATK-12 | Angeheftet-Überflutung | ⚠️ EXPOSED |

**7 BLOCKED, 2 SAFE, 3 EXPOSED** — Kritisch: Bereinigung authentifizieren und auf eigene Daten des Akteurs beschränken; Titel/Body-Längenbeschränkungen hinzufügen; angeheftete Notiz-Anzahl pro Benutzer begrenzen.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Hard Delete beim ersten DELETE | Kein Wiederherstellungspfad; versehentliches Löschen ist dauerhaft |
| Kein `deleted_at IS NULL`-Filter in Listen/Get-Abfragen | Soft-gelöschte Elemente erscheinen wieder als noch aktiv |
| `PUT` auf soft-gelöschten Notizen erlauben | Ghost-Schreibvorgänge — Benutzer bearbeiten Daten, die sie für gelöscht hielten |
| Keine Auth für `POST /trash/purge` | Jeder Client zerstört irreversibel alle Papierkorb-Daten |
| 403 für soft-gelöschte Notiz-GET zurückgeben | Enthüllt, dass die Notiz existiert; 404 verhindert Existenz-Enumeration |
| Keine Zeilenanzahl-Prüfung nach Soft Delete | Stilles 200 wenn Notiz nicht gefunden; betroffene Zeilen immer prüfen |
