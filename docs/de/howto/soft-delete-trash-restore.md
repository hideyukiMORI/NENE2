# How-to: Soft Delete, Papierkorb & Wiederherstellungs-API

> **FT-Referenz**: FT340 (`NENE2-FT/softlog`) — Notizen-API mit Soft Delete (`deleted_at`), Papierkorb-Ansicht,
> Wiederherstellen, dauerhaftes Hard Delete, Massen-Purge, Angepinnt-zuerst-Sortierung und ATK
> Cracker-Mindset-Angriffstest; 26 Tests / 60+ Assertions PASS.

Diese Anleitung zeigt, wie ein zweistufiger Löschlebenszyklus implementiert wird: Elemente werden
zuerst soft-gelöscht (in den Papierkorb verschoben) und können wiederhergestellt werden, dann via
explizitem Hard Delete oder Massen-Purge dauerhaft gelöscht.

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

| Methode   | Pfad | Beschreibung |
|-----------|------|-------------|
| `POST`    | `/notes` | Notiz erstellen |
| `GET`     | `/notes` | Aktive Notizen auflisten (angepinnte zuerst) |
| `GET`     | `/notes/{id}` | Aktive Notiz abrufen |
| `PUT`     | `/notes/{id}` | Aktive Notiz aktualisieren |
| `DELETE`  | `/notes/{id}` | Soft Delete (→ Papierkorb) |
| `GET`     | `/notes/trash` | Papierkorb-Notizen auflisten |
| `POST`    | `/notes/{id}/restore` | Aus Papierkorb wiederherstellen |
| `DELETE`  | `/notes/{id}/permanent` | Hard Delete (dauerhaft) |
| `POST`    | `/notes/trash/purge` | Gesamten Papierkorb leeren |

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

## Aktive Notizen auflisten (Angepinnte zuerst)

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

`deleted_at` ist für alle Papierkorb-Elemente nicht-null.

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

## Papierkorb leeren

```php
POST /notes/trash/purge
→ 200  {"purged": 2}

// Leerer Papierkorb
POST /notes/trash/purge  → 200  {"purged": 0}
```

`purge` führt `DELETE FROM notes WHERE deleted_at IS NOT NULL` aus und gibt die Zeilenanzahl zurück.

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Hard Delete ohne vorherigen Soft Delete 🚫 BLOCKIERT

**Angriff**: Angreifer ruft `DELETE /notes/1/permanent` auf einer aktiven (noch nicht soft-gelöschten) Notiz auf.
**Ergebnis**: BLOCKIERT — `DELETE /notes/{id}/permanent` prüft `deleted_at IS NOT NULL` vor dem Fortfahren. Aktive Notizen geben 404 an den permanent-delete-Endpunkt zurück; nur Papierkorb-Elemente können hard-deleted werden.

---

### ATK-02 — Auf soft-gelöschte Notiz via direktem GET zugreifen ✅ SICHER

**Angriff**: Angreifer kennt Notiz-ID 5, die soft-gelöscht wurde, und ruft `GET /notes/5` auf, um geschützten Inhalt zu lesen.
**Ergebnis**: SICHER — `GET /notes/{id}` fragt `WHERE id = ? AND deleted_at IS NULL` ab. Soft-gelöschte Notizen geben 404 identisch mit unbekannten Notizen zurück — kein Existenzhinweis.

---

### ATK-03 — Papierkorb ohne Auth leeren (Massenzerstörung) ⚠️ EXPONIERT

**Angriff**: Jeder Client ruft `POST /notes/trash/purge` auf, um alle im Papierkorb befindlichen Notizen aller Benutzer dauerhaft zu vernichten.
**Ergebnis**: EXPONIERT — Es gibt keine Authentifizierungsprüfung auf `POST /notes/trash/purge`. Ohne Pro-Benutzer-Scoping kann ein nicht-authentifizierter Client alle Papierkorb-Daten aller Benutzer irreversibel löschen. Abhilfe: Authentifizierung erforderlich machen; Purge auf den Papierkorb des authentifizierten Benutzers beschränken; Admin-Rolle für globalen Purge erforderlich.

---

### ATK-04 — Doppeltes Soft Delete korrumpiert deleted_at ✅ SICHER

**Angriff**: Angreifer sendet `DELETE /notes/1` zweimal, in der Hoffnung, dass der zweite Aufruf `deleted_at` auf einen späteren Zeitstempel zurücksetzt.
**Ergebnis**: SICHER — Der erste Delete setzt `deleted_at`. Der zweite Delete findet `deleted_at IS NULL = false`, also gibt die Suche 0 Zeilen zurück → 404. Der Zeitstempel wird nicht geändert.

---

### ATK-05 — Aktive Notiz wiederherstellen (Zustand korrumpieren) 🚫 BLOCKIERT

**Angriff**: Angreifer ruft `POST /notes/1/restore` auf einer aktiven (nicht-gelöschten) Notiz auf, um `deleted_at = null` unbedingt zu erzwingen.
**Ergebnis**: BLOCKIERT — `restore` fragt `WHERE id = ? AND deleted_at IS NOT NULL` ab. Aktive Notizen passen nicht → 404. Idempotent: eine bereits-aktive Notiz wiederherzustellen ist ein No-Op 404.

---

### ATK-06 — SQL-Injection via Titel beim Erstellen ✅ SICHER

**Angriff**: Angreifer reicht `{"title": "'; DROP TABLE notes; --"}` ein, um die Datenbank zu korrumpieren.
**Ergebnis**: SICHER — Alle Schreibvorgänge verwenden parametrisierte Statements. Titel wird als Literalzeichenkette gespeichert.

---

### ATK-07 — Notiz-ID überlaufen um Validierung zu umgehen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `GET /notes/99999999999999999999` (20-stellig) um PHP-Integer zu überlaufen und unbeabsichtigte IDs zu erreichen.
**Ergebnis**: BLOCKIERT — Notiz-IDs werden mit `ctype_digit` + `strlen <= 18` vor der Konvertierung validiert. Überlauf-Werte → 422.

---

### ATK-08 — Gelöschte Notiz aktualisieren (Ghost schreiben) 🚫 BLOCKIERT

**Angriff**: Angreifer hat eine veraltete Sitzungsreferenz auf eine gelöschte Notiz und reicht PUT ein, um sie zu ändern.
**Ergebnis**: BLOCKIERT — `PUT /notes/{id}` fragt `WHERE id = ? AND deleted_at IS NULL` ab. Soft-gelöschte Notizen bestehen diese Prüfung nicht → 404. Die Aktualisierung wird abgelehnt.

---

### ATK-09 — Race: Wiederherstellen dann sofort Purge 🚫 BLOCKIERT

**Angriff**: Angreifer wetteifert `POST /notes/1/restore` und `POST /notes/trash/purge`, um eine Notiz mitten im Wiederherstellen zu zerstören.
**Ergebnis**: BLOCKIERT — Jede Operation ist eine einzelne atomare DB-Transaktion. Das Purge führt `DELETE WHERE deleted_at IS NOT NULL` aus; das Wiederherstellen setzt `deleted_at = NULL`. Eines gewinnt und die Notiz endet in einem konsistenten Zustand.

---

### ATK-10 — Gleichzeitiges Soft Delete hinterlässt Waise ✅ SICHER

**Angriff**: Zwei Anfragen rufen gleichzeitig `DELETE /notes/1` auf. Beide prüfen `deleted_at IS NULL`, sehen null, und versuchen `deleted_at` zu setzen.
**Ergebnis**: SICHER — Das erste Update gelingt. Das zweite findet `deleted_at IS NOT NULL` (oder 0 aktualisierte Zeilen) → 404. SQLite serialisiert Schreibvorgänge; der zweite Aufruf ist auf DB-Ebene idempotent.

---

### ATK-11 — Titel zu lang (Speichermissbrauch) ⚠️ EXPONIERT

**Angriff**: Angreifer reicht eine 10 MB lange Titelzeichenkette ein, um den Datenbankspeicher zu erschöpfen.
**Ergebnis**: EXPONIERT — Es wird keine maximale Länge für `title` oder `body` erzwungen. Abhilfe: `MAX_TITLE_LENGTH` (z.B. 500 Zeichen) und `MAX_BODY_LENGTH` (z.B. 100.000 Zeichen) hinzufügen und 422 zurückgeben wenn überschritten. Request-Size-Middleware bietet einen sekundären Schutz.

---

### ATK-12 — Angepinnt-Überschwemmung (Angepinnte Notizen fluten) ⚠️ EXPONIERT

**Angriff**: Angreifer erstellt Tausende angepinnter Notizen, um alle echten Notizen vom Anfang der aktiven Liste zu verdrängen.
**Ergebnis**: EXPONIERT — Keine Begrenzung der Anzahl angepinnter Notizen. Jede Notiz kann mit `is_pinned: true` erstellt werden. Abhilfe: maximale Anzahl angepinnter Notizen pro Benutzer begrenzen (z.B. 10); 422 zurückgeben wenn überschritten.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | Hard Delete ohne Soft Delete | 🚫 BLOCKIERT |
| ATK-02 | Auf soft-gelöschte Notiz per GET zugreifen | ✅ SICHER |
| ATK-03 | Papierkorb ohne Auth leeren | ⚠️ EXPONIERT |
| ATK-04 | Doppeltes Soft Delete | ✅ SICHER |
| ATK-05 | Aktive Notiz wiederherstellen | 🚫 BLOCKIERT |
| ATK-06 | SQL-Injection via Titel | ✅ SICHER |
| ATK-07 | Notiz-ID überlaufen | 🚫 BLOCKIERT |
| ATK-08 | Soft-gelöschte Notiz aktualisieren | 🚫 BLOCKIERT |
| ATK-09 | Race: Wiederherstellen + Purge | 🚫 BLOCKIERT |
| ATK-10 | Gleichzeitiges Soft Delete | ✅ SICHER |
| ATK-11 | Titel zu lang | ⚠️ EXPONIERT |
| ATK-12 | Angepinnt-Überschwemmung | ⚠️ EXPONIERT |

**7 BLOCKIERT, 2 SICHER, 3 EXPONIERT** — Kritisch: Purge authentifizieren und auf Daten des Akteurs beschränken; Titel/Body-Längenbegrenzungen hinzufügen; Anzahl angepinnter Notizen pro Benutzer begrenzen.

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Hard-Delete beim ersten DELETE | Kein Wiederherstellungspfad; versehentliches Löschen ist dauerhaft |
| Kein `deleted_at IS NULL`-Filter in List/Get-Abfragen | Soft-gelöschte Elemente erscheinen wieder als aktiv |
| `PUT` auf soft-gelöschten Notizen erlauben | Ghost-Schreibvorgänge — Benutzer bearbeiten Daten, die sie gelöscht glaubten |
| Keine Auth auf `POST /trash/purge` | Jeder Client vernichtet irreversibel alle Papierkorb-Daten |
| 403 für soft-gelöschte Notiz bei GET zurückgeben | Gibt Existenz der Notiz preis; 404 verhindert Existenz-Enumeration |
| Keine Zeilenanzahl-Prüfung nach Soft Delete | Stilles 200 wenn Notiz nicht gefunden; betroffene Zeilen immer prüfen |
