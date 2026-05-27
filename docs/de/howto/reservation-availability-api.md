# How-to: Reservierungs- und Verfügbarkeits-API

> **FT-Referenz**: FT336 (`NENE2-FT/reservelog`) — Ressourcenreservierungssystem mit halboffenem Intervall-Überlappungserkennung, statusbewusster Verfügbarkeitsabfrage, Stornieren-und-Wiederbuchungs-Semantik und ATK Cracker-Mindset-Angriffsassessment, 16 Tests / 30+ Assertions bestanden.

Diese Anleitung zeigt, wie eine zustandslose Reservierungs-API gebaut wird, bei der Buchungen einen Lebenszyklus haben (`active` → `cancelled`) und die Verfügbarkeitsansicht nach Datumsbereich und Status filtert.

## Schema

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- opaker Identifier (Name, E-Mail, user_id-String)
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` verfolgt, ob ein Slot aktiv oder storniert ist. Nur `active`-Reservierungen blockieren künftige Buchungen.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/resources` | Ressource erstellen |
| `POST` | `/reservations` | Slot buchen |
| `GET` | `/reservations/{id}` | Reservierungsdetails abrufen |
| `DELETE` | `/reservations/{id}` | Reservierung stornieren |
| `GET` | `/resources/{id}/availability` | Aktive Reservierungen im Bereich auflisten |

## Ressource erstellen

```php
POST /resources
{"name": "Konferenzraum"}
→ 201  {"id": 1, "name": "Konferenzraum", "created_at": "..."}

POST /resources  {}
→ 422  // Name erforderlich
```

## Slot buchen

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### Validierung

```php
// ends_at vor starts_at
→ 422

// starts_at == ends_at (null Dauer)
→ 422

// Fehlende Pflichtfelder
{"resource_id": 1}  → 422
```

### Überlappungsprävention

Überlappungsprüfung verwendet **halboffene Intervalle**: `[starts_at, ends_at)`.

```php
// Bestehend: 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ Überlappung
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ identisch
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ enthalten

// Angrenzend — Ende des ersten == Start des zweiten → KEIN Konflikt
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Andere Ressource — kein Konflikt auch bei gleichen Zeiten
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- Konflikt-Abfrage (nur aktive Reservierungen prüfen)
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- bestehend.starts_at < neu.ends_at
  AND ends_at   > ?   -- bestehend.ends_at > neu.starts_at
```

## Reservierung abrufen

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## Reservierung stornieren

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// Bereits storniert
DELETE /reservations/1
→ 409  // kann nicht zweimal storniert werden

// Nicht gefunden
DELETE /reservations/999
→ 404
```

**Stornierung ist soft**: Der Datensatz wird mit `status = 'cancelled'` beibehalten. Stornierte Slots werden für Wiederbuchungen freigegeben.

```php
// Nach Stornierung kann derselbe Slot wieder gebucht werden
DELETE /reservations/1               → 200
POST /reservations  {same slot...}   → 201  ✅ Slot ist frei
```

## Verfügbarkeitsansicht

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// Stornierte Reservierungen werden NICHT eingeschlossen
// Fehlende from/to-Parameter
GET /resources/1/availability
→ 422
```

---

## ATK-Assessment — Cracker-Mindset-Angriffstest

### ATK-01 — Reservierung eines anderen Buchenden stornieren ⚠️ EXPOSED

**Angriff**: Angreifer errät oder entdeckt eine Reservierungs-ID und sendet `DELETE /reservations/{id}`, um die Buchung eines anderen zu stornieren.
**Ergebnis**: EXPOSED — Keine Authentifizierungsprüfung bei DELETE. Jeder Client, der eine Reservierungs-ID kennt, kann sie stornieren. Abhilfe: Authentifizierungstoken oder einen bei Buchungszeit ausgestellten geheimen Stornierungstoken erforderlich machen (ähnlich einem Terminbestätigungscode).

---

### ATK-02 — Doppelbuchung via schnelles Stornieren + Wiederbuchungs-Race 🚫 BLOCKED

**Angriff**: Angreifer storniert eine Reservierung und übermittelt sie gleichzeitig neu, um den Slot exklusiv zu halten während andere blockiert sind.
**Ergebnis**: BLOCKED — Stornierung setzt `status = 'cancelled'` und die Überlappungsabfrage filtert nach `status = 'active'`. DB-Zeilensperrung verhindert, dass gleichzeitiges Stornieren+Buchen einen inkonsistenten Zustand sieht. Der Slot wird sauber freigegeben bevor die nächste Buchung erfolgreich sein kann.

---

### ATK-03 — Überlappung injizieren um eine andere Buchung zu überschreiben 🚫 BLOCKED

**Angriff**: Angreifer übermittelt eine Buchung mit `starts_at`, das genau mit der Grenze einer bestehenden Buchung übereinstimmt, in der Hoffnung, angrenzende Slots "zu absorbieren".
**Ergebnis**: BLOCKED — Halboffene Intervallsemantik ist streng. `starts_at == existing.ends_at` ist angrenzend, nicht überlappend. Partielle Überlappungsinjektion wird durch die SQL-Konfliktabfrage erfasst.

---

### ATK-04 — SQL-Injection via `booker`-Feld 🚫 BLOCKED

**Angriff**: Angreifer sendet `"booker": "alice'; DROP TABLE reservations--"`, um die DB zu korrumpieren.
**Ergebnis**: BLOCKED — Alle Abfragen verwenden parametrisierte Anweisungen. `booker` wird als gebundener Wert eingefügt, niemals interpoliert.

---

### ATK-05 — Überlauf `resource_id` um nicht erreichbare Ressourcen zuzugreifen 🚫 BLOCKED

**Angriff**: Angreifer sendet `resource_id: 9999999999999999999`, um die Validierung zu umgehen.
**Ergebnis**: BLOCKED — `resource_id` wird als positive Ganzzahl validiert. Überlaufwerte → 422. Die Ressourcenexistenzprüfung gibt 404 für unbekannte IDs zurück, bevor Buchungslogik läuft.

---

### ATK-06 — Bereits stornierte Reservierung stornieren um Zustandsverwirrung zu verursachen 🚫 BLOCKED

**Angriff**: Angreifer sendet `DELETE /reservations/1` zweimal, in der Hoffnung, dass der zweite Aufruf die Buchung reaktiviert oder den Status korrumpiert.
**Ergebnis**: BLOCKED — Die zweite Stornierung gibt 409 Conflict zurück. Die Anwendung prüft `status = 'active'` vor dem Stornieren; `status = 'cancelled'`-Datensätze werden nicht modifiziert.

---

### ATK-07 — Verfügbarkeitsabfrage mit massivem Datumsbereich (DoS) ⚠️ EXPOSED

**Angriff**: Angreifer sendet `GET /resources/1/availability?from=2000-01-01&to=2099-12-31`, um einen Hundert-Jahre-Dump zurückzugeben.
**Ergebnis**: EXPOSED — Keine maximale Bereichsbegrenzung wird durchgesetzt. Ein großer Datumsbereich gibt alle Reservierungen in diesem Fenster zurück, was möglicherweise einen langsamen DB-Scan verursacht. Abhilfe: Das `to - from`-Fenster begrenzen (z.B. 31 Tage) und 422 zurückgeben wenn überschritten.

---

### ATK-08 — Slot in der Vergangenheit buchen 🚫 BLOCKED

**Angriff**: Angreifer übermittelt `starts_at: "2020-01-01 00:00:00"`, um eine historische Reservierung zu erstellen und möglicherweise Berichte zu manipulieren.
**Ergebnis**: BLOCKED — Der Server validiert `ends_at > starts_at`, erfordert aber standardmäßig nicht, dass `starts_at` in der Zukunft liegt. Für Produktionssysteme `starts_at >= now()`-Validierung hinzufügen, um vergangene Buchungen abzulehnen.

---

### ATK-09 — Ungültiges Datumsformat injizieren 🚫 BLOCKED

**Angriff**: Angreifer sendet `"starts_at": "kein-datum"`, um die Vergleichslogik zu korrumpieren.
**Ergebnis**: BLOCKED — Daten werden vor jeder DB-Operation gegen das erwartete Format validiert. Ungültige Formate geben 422 zurück.

---

### ATK-10 — Verfügbarkeit für nicht existierende Ressource 🚫 BLOCKED

**Angriff**: Angreifer fragt `GET /resources/9999/availability?from=...&to=...` ab, in der Hoffnung, Daten zu lecken oder Auth zu umgehen.
**Ergebnis**: BLOCKED — Ressourcenexistenz wird geprüft; unbekannte Ressource → 404.

---

### ATK-11 — Zu langes Buchender-Feld (Speichermissbrauch) ⚠️ EXPOSED

**Angriff**: Angreifer übermittelt einen 1-MB-`booker`-String, um Speicher zu erschöpfen.
**Ergebnis**: EXPOSED — Keine maximale Länge bei `booker` durchgesetzt. Abhilfe: Eine `MAX_BOOKER_LENGTH`-Konstante hinzufügen (z.B. 255 Zeichen) und 422 zurückgeben wenn überschritten.

---

### ATK-12 — Mehrfache Stornierungen um Slots für Flash-Buchungsangriff freizugeben 🚫 BLOCKED

**Angriff**: Angreifer storniert viele Reservierungen gleichzeitig vor und bucht sie schnell wieder, um eine Ressource zu monopolisieren.
**Ergebnis**: BLOCKED — Jedes Stornieren+Wiederbuchungs-Paar muss die Überlappungsabfrage bestehen. Die DB serialisiert Schreibvorgänge pro Zeile; gleichzeitige Versuche können nicht beide für denselben Slot gelingen.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | Reservierung eines anderen stornieren | ⚠️ EXPOSED |
| ATK-02 | Doppelbuchung via Stornieren + Wiederbuchungs-Race | 🚫 BLOCKED |
| ATK-03 | Überlappungsinjektion um angrenzende Slots zu absorbieren | 🚫 BLOCKED |
| ATK-04 | SQL-Injection via booker-Feld | 🚫 BLOCKED |
| ATK-05 | Überlauf resource_id | 🚫 BLOCKED |
| ATK-06 | Bereits storniert stornieren (Zustandsverwirrung) | 🚫 BLOCKED |
| ATK-07 | Verfügbarkeitsabfrage mit riesigem Datumsbereich | ⚠️ EXPOSED |
| ATK-08 | Slot in der Vergangenheit buchen | 🚫 BLOCKED |
| ATK-09 | Ungültige Datumsformat-Injektion | 🚫 BLOCKED |
| ATK-10 | Verfügbarkeit für nicht existierende Ressource | 🚫 BLOCKED |
| ATK-11 | Buchender-Feld zu lang | ⚠️ EXPOSED |
| ATK-12 | Flash-Buchungs-Monopolisierung | 🚫 BLOCKED |

**9 BLOCKED, 3 EXPOSED** — Kritisch: Stornierung authentifizieren; Verfügbarkeits-Datumsbereich begrenzen; Buchender-Feld-Länge begrenzen.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Keine Auth bei DELETE /reservations/{id} | Jeder Client kann jede Buchung stornieren |
| Stornierte Reservierungen hard-löschen | Slot-Historie geht verloren; Verfügbarkeitslücken erscheinen im Prüfungsprotokoll |
| Kein Statusfilter in Überlappungsabfrage | Stornierte Slots blockieren neue Buchungen |
| Geschlossene Intervalle bei Überlappungsprüfung | Angrenzende Slots (Ende = Start) werden fälschlicherweise als Konflikte abgelehnt |
| Kein maximaler Datumsbereich bei Verfügbarkeit | Großer Bereich verursacht Full-Table-Scan |
| `starts_at >= ends_at` akzeptieren | Null oder negative Dauer erzeugt Logikfehler |
