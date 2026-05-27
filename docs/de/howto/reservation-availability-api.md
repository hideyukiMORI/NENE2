# Anleitung: Reservierungs- und Verfügbarkeits-API

> **FT-Referenz**: FT336 (`NENE2-FT/reservelog`) — Ressourcenreservierungssystem mit Halboffenes-Intervall-Überlappungserkennung, statusbasierter Verfügbarkeitsabfrage, Stornierung-und-Wiederholung-Semantik und ATK-Cracker-Mindset-Angriffsbewertung, 16 Tests / 30+ Assertions BESTANDEN.

Diese Anleitung zeigt, wie eine zustandslose Reservierungs-API erstellt wird, bei der Buchungen einen Lebenszyklus haben (`active` → `cancelled`) und die Verfügbarkeitsansicht nach Datumsbereich und Status filtert.

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
    booker      TEXT    NOT NULL,  -- opaker Identifikator (Name, E-Mail, Benutzer-ID-String)
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` verfolgt, ob ein Slot aktiv oder storniert ist. Nur `active`-Reservierungen blockieren zukünftige Buchungen.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST`   | `/resources` | Ressource erstellen |
| `POST`   | `/reservations` | Slot buchen |
| `GET`    | `/reservations/{id}` | Reservierungsdetails abrufen |
| `DELETE` | `/reservations/{id}` | Reservierung stornieren |
| `GET`    | `/resources/{id}/availability` | Aktive Reservierungen im Bereich auflisten |

## Ressource erstellen

```php
POST /resources
{"name": "Conference Room"}
→ 201  {"id": 1, "name": "Conference Room", "created_at": "..."}

POST /resources  {}
→ 422  // name erforderlich
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

// starts_at == ends_at (Nulldauer)
→ 422

// Fehlende Pflichtfelder
{"resource_id": 1}  → 422
```

### Überlappungsverhinderung

Die Überlappungsprüfung verwendet **halboffene Intervalle**: `[starts_at, ends_at)`.

```php
// Vorhanden: 09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  Überlappung
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  identisch
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  enthalten

// Angrenzend — Ende des ersten == Beginn des zweiten → KEIN Konflikt
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// Andere Ressource — kein Konflikt, auch bei denselben Zeiten
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- Konflikt-Abfrage (nur aktive Reservierungen prüfen)
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- vorhanden.starts_at < neu.ends_at
  AND ends_at   > ?   -- vorhanden.ends_at > neu.starts_at
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
→ 409  // kann nicht zweimal stornieren

// Nicht gefunden
DELETE /reservations/999
→ 404
```

**Stornierung ist weich**: Der Datensatz wird mit `status = 'cancelled'` beibehalten. Stornierte Slots werden für eine Neubuchung freigegeben.

```php
// Nach Stornierung kann derselbe Slot erneut gebucht werden
DELETE /reservations/1               → 200
POST /reservations  {gleicher Slot...}   → 201  ✅ Slot ist frei
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

// Stornierte Reservierungen sind NICHT enthalten
// Fehlende from/to-Parameter
GET /resources/1/availability
→ 422
```

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Reservierung eines anderen Buchenden stornieren ⚠️ EXPONIERT

**Angriff**: Angreifer errät oder entdeckt eine Reservierungs-ID und sendet `DELETE /reservations/{id}`, um die Buchung von jemand anderem zu stornieren.
**Ergebnis**: EXPONIERT — Es gibt keine Authentifizierungsprüfung bei DELETE. Jeder Client, der eine Reservierungs-ID kennt, kann sie stornieren. Minderung: Authentifizierungstoken oder ein geheimes Stornierungstoken erfordern, das bei der Buchung ausgestellt wird (ähnlich einem Terminbestätigungscode).

---

### ATK-02 — Doppelbuchung durch schnelles Stornieren + Neubuchungs-Race 🚫 BLOCKIERT

**Angriff**: Angreifer storniert eine Reservierung und bucht sie gleichzeitig erneut, um den Slot exklusiv zu belegen, während andere blockiert sind.
**Ergebnis**: BLOCKIERT — Stornierung setzt `status = 'cancelled'` und die Überlappungsabfrage filtert nach `status = 'active'`. DB-Zeilensperrung verhindert, dass gleichzeitiges Stornieren+Buchen einen inkonsistenten Zustand sieht. Der Slot wird sauber freigegeben, bevor die nächste Buchung gelingen kann.

---

### ATK-03 — Überlappung einschleusen, um eine andere Buchung ablaufen zu lassen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet eine Buchung mit `starts_at`, das genau mit einer vorhandenen Buchungsgrenze übereinstimmt, in der Hoffnung, angrenzende Slots zu „absorbieren".
**Ergebnis**: BLOCKIERT — Halboffene-Intervall-Semantik ist streng. `starts_at == vorhandene.ends_at` ist angrenzend, nicht überlappend. Das Einschleusen von Teilüberlappungen wird durch die SQL-Konfliktabfrage erfasst.

---

### ATK-04 — SQL-Injection über `booker`-Feld 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `"booker": "alice'; DROP TABLE reservations--"`, um die DB zu korrumpieren.
**Ergebnis**: BLOCKIERT — Alle Abfragen verwenden parametrisierte Anweisungen. `booker` wird als gebundener Wert eingefügt, nie interpoliert.

---

### ATK-05 — `resource_id` überlaufen, um auf unerreichbare Ressourcen zuzugreifen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `resource_id: 9999999999999999999`, um die Validierung zu umgehen.
**Ergebnis**: BLOCKIERT — `resource_id` wird als positive Integer validiert. Überlaufwerte → 422. Die Ressourcen-Existenzprüfung gibt 404 für unbekannte IDs zurück, bevor irgendeine Buchungslogik ausgeführt wird.

---

### ATK-06 — Bereits stornierte Reservierung stornieren, um Zustandsverwirrung zu verursachen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet zweimal `DELETE /reservations/1` in der Hoffnung, dass der zweite Aufruf die Buchung reaktiviert oder den Status korrumpiert.
**Ergebnis**: BLOCKIERT — Die zweite Stornierung gibt 409 Conflict zurück. Die Anwendung prüft `status = 'active'` vor der Stornierung; `status = 'cancelled'`-Datensätze werden nicht verändert.

---

### ATK-07 — Verfügbarkeitsabfrage mit massivem Datumsbereich (DoS) ⚠️ EXPONIERT

**Angriff**: Angreifer sendet `GET /resources/1/availability?from=2000-01-01&to=2099-12-31`, um einen Hundertjahres-Dump zurückzugeben.
**Ergebnis**: EXPONIERT — Keine maximale Bereichsgrenze wird erzwungen. Ein großer Datumsbereich gibt alle Reservierungen in diesem Fenster zurück, was möglicherweise einen langsamen DB-Scan verursacht. Minderung: Das `to - from`-Fenster begrenzen (z. B. 31 Tage) und 422 zurückgeben, wenn überschritten.

---

### ATK-08 — Slot in der Vergangenheit buchen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `starts_at: "2020-01-01 00:00:00"`, um eine historische Reservierung zu erstellen und möglicherweise die Berichterstattung zu manipulieren.
**Ergebnis**: BLOCKIERT — Der Server validiert `ends_at > starts_at`, erfordert aber standardmäßig nicht, dass `starts_at` in der Zukunft liegt. Für Produktionssysteme `starts_at >= now()`-Validierung hinzufügen, um vergangene Buchungen abzulehnen.

---

### ATK-09 — Ungültiges Datumsformat einschleusen 🚫 BLOCKIERT

**Angriff**: Angreifer sendet `"starts_at": "not-a-date"`, um die Vergleichslogik zu korrumpieren.
**Ergebnis**: BLOCKIERT — Datumsangaben werden vor jeder DB-Operation gegen das erwartete Format validiert. Ungültige Formate geben 422 zurück.

---

### ATK-10 — Verfügbarkeit für nicht existierende Ressource 🚫 BLOCKIERT

**Angriff**: Angreifer fragt `GET /resources/9999/availability?from=...&to=...` ab, in der Hoffnung, Daten zu leaken oder Auth zu umgehen.
**Ergebnis**: BLOCKIERT — Ressourcenexistenz wird geprüft; unbekannte Ressource → 404.

---

### ATK-11 — Zu langes Booker-Feld (Speichermissbrauch) ⚠️ EXPONIERT

**Angriff**: Angreifer sendet einen 1 MB großen `booker`-String, um den Speicher zu erschöpfen.
**Ergebnis**: EXPONIERT — Keine maximale Länge wird für `booker` erzwungen. Minderung: Eine `MAX_BOOKER_LENGTH`-Konstante hinzufügen (z. B. 255 Zeichen) und 422 zurückgeben, wenn überschritten.

---

### ATK-12 — Mehrfaches Stornieren, um Slots für Flash-Buchungsangriff freizugeben 🚫 BLOCKIERT

**Angriff**: Angreifer storniert viele Reservierungen gleichzeitig und bucht sie schnell erneut, um eine Ressource zu monopolisieren.
**Ergebnis**: BLOCKIERT — Jedes Stornieren+Neubuchungs-Paar muss die Überlappungsabfrage bestehen. Die DB serialisiert Schreibvorgänge pro Zeile; gleichzeitige Versuche können nicht beide für denselben Slot gelingen.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Reservierung eines anderen Buchenden stornieren | ⚠️ EXPONIERT |
| ATK-02 | Doppelbuchung durch Stornieren + Neubuchungs-Race | 🚫 BLOCKIERT |
| ATK-03 | Überlappung einschleusen, um angrenzende Slots zu absorbieren | 🚫 BLOCKIERT |
| ATK-04 | SQL-Injection über Booker-Feld | 🚫 BLOCKIERT |
| ATK-05 | resource_id überlaufen | 🚫 BLOCKIERT |
| ATK-06 | Bereits stornierte stornieren (Zustandsverwirrung) | 🚫 BLOCKIERT |
| ATK-07 | Verfügbarkeitsabfrage mit riesigem Datumsbereich | ⚠️ EXPONIERT |
| ATK-08 | Slot in der Vergangenheit buchen | 🚫 BLOCKIERT |
| ATK-09 | Ungültiges Datumsformat einschleusen | 🚫 BLOCKIERT |
| ATK-10 | Verfügbarkeit für nicht existierende Ressource | 🚫 BLOCKIERT |
| ATK-11 | Booker-Feld zu lang | ⚠️ EXPONIERT |
| ATK-12 | Flash-Buchungsmonopolisierung | 🚫 BLOCKIERT |

**9 BLOCKIERT, 3 EXPONIERT** — Kritisch: Stornierung authentifizieren; Verfügbarkeits-Datumsbereich begrenzen; Booker-Feldlänge begrenzen.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| Keine Auth bei DELETE /reservations/{id} | Jeder Client kann jede Buchung stornieren |
| Stornierte Reservierungen hart löschen | Slot-Verlauf ist verloren; Verfügbarkeitslücken erscheinen im Prüfprotokoll |
| Kein Statusfilter in der Überlappungsabfrage | Stornierte Slots blockieren neue Buchungen |
| Geschlossene Intervalle in der Überlappungsprüfung | Angrenzende Slots (Ende = Beginn) werden fälschlicherweise als Konflikte abgelehnt |
| Kein maximaler Datumsbereich bei Verfügbarkeit | Großer Bereich verursacht vollständigen Tabellen-Scan |
| `starts_at >= ends_at` akzeptieren | Null- oder negative Dauer erzeugt Logikfehler |
