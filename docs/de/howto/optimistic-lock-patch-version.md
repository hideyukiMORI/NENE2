# How-to: Optimistische Sperre mit PATCH + Versionsfeld

> **FT-Referenz**: FT324 (`NENE2-FT/optlocklog`) — PATCH-basierte optimistische Sperre, 409 enthält `current_version` für Zero-GET-Neuversuch, strenger Integer-Versionstyp, ATK-Bewertung, 12 Tests / 24 Assertions PASS.

Diese Anleitung zeigt, wie optimistische Nebenläufigkeitskontrolle via PATCH mit einem `version`-Feld implementiert wird, wobei die aktuelle Serverversion in 409-Antworten zurückgegeben wird, sodass Clients ohne extra GET erneut versuchen können.

## Schema

```sql
CREATE TABLE articles (
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
| `POST`  | `/articles` | Erstellen (version=1) |
| `GET`   | `/articles/{id}` | Abrufen mit Version |
| `PATCH` | `/articles/{id}` | Aktualisieren (Version als Integer erforderlich) |

## Erstellen und Lesen

```php
POST /articles  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}

GET /articles/1
→ 200  {"id": 1, "title": "Hello", "version": 1}
```

## PATCH mit Version

```php
PATCH /articles/1
{"title": "Updated", "body": "New body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

Die Version muss ein **JSON-Integer** sein — ein String `"1"` wird abgelehnt.

## 409 enthält current_version

Wenn ein Konflikt erkannt wird, enthält die Antwort `current_version`, sodass der Client ohne erneutes GET erneut versuchen kann:

```php
// Version 1 bereits auf 2 hochgesetzt durch einen anderen Schreiber
PATCH /articles/1  {"title": "X", "version": 1}
→ 409
{
  "type": "https://nene2.dev/problems/conflict",
  "title": "Conflict",
  "status": 409,
  "current_version": 2    ← Client kann dies direkt für den Neuversuch verwenden
}

// Client versucht mit current_version aus 409-Body erneut
PATCH /articles/1  {"title": "X", "version": 2}
→ 200  {"version": 3}     ← Erfolg
```

## Typvalidierung

```php
PATCH /articles/1  {"title": "x", "body": "x"}          → 400  // fehlende Version
PATCH /articles/1  {"title": "x", "body": "x", "version": "1"} → 400  // String nicht int
PATCH /articles/9999 {"version": 1}                      → 404  // nicht gefunden
```

## Implementierung

```php
private function patch(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    // Strikte Integer-Typprüfung — "1" (String) wird abgelehnt
    if (!is_int($version)) {
        return $this->json->create(['error' => 'version must be an integer'], 400);
    }

    $article = $this->repo->findById($id);
    if ($article === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($article['version'] !== $version) {
        return $this->problems->create('conflict', 'Version conflict', 409, [
            'current_version' => $article['version'],  // ← Zero-GET-Neuversuch ermöglichen
        ]);
    }

    // Atomares UPDATE mit WHERE version = ?
    $updated = $this->repo->updateWithVersion($id, $title, $body, $version + 1, $now);
    return $this->json->create($updated);
}
```

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Versions-Brute-Force zum Überschreiben ✅ SAFE

**Angriff**: Angreifer durchläuft Version `1, 2, 3...` bis eine erfolgreich ist und überschreibt den aktuellen Inhalt.
**Ergebnis**: SAFE — Brute Force findet letztendlich die aktuelle Version, aber dies ist ein legitimer Schreibvorgang, keine Privilegieneskalation. Eigentümerschaftsautorisierung (nicht gezeigt) verhindert unbefugtes Schreiben.

---

### ATK-02 — String-Versions-Bypass (`"version": "1"`) 🚫 BLOCKED

**Angriff**: Angreifer sendet `"version": "1"` (JSON-String) in der Hoffnung, PHP-Typkoercion behandle es als Integer.
**Ergebnis**: BLOCKED — `is_int($version)` gibt false für Strings zurück. Gibt 400 zurück.

---

### ATK-03 — Float-Version (`"version": 1.0`) 🚫 BLOCKED

**Angriff**: `"version": 1.0` senden, um über lockere Vergleiche zu matchen.
**Ergebnis**: BLOCKED — `is_int(1.0)` ist in PHP false (es ist ein Float). Gibt 400 zurück.

---

### ATK-04 — Fehlende Version → Blindes Schreiben erzwingen 🚫 BLOCKED

**Angriff**: `version`-Feld weglassen in der Hoffnung, der Server akzeptiere das Update standardmäßig.
**Ergebnis**: BLOCKED — Fehlende `version` (null) besteht `is_int()`-Prüfung nicht. Gibt 400 zurück.

---

### ATK-05 — Negative Version 🚫 BLOCKED

**Angriff**: `"version": -1` senden, um potenziellen Off-by-One beim Versionsvergleich auszunutzen.
**Ergebnis**: BLOCKED — Version beginnt bei 1 und wird nur inkrementiert. `-1 !== 1` → 409 Conflict.

---

### ATK-06 — current_version aus 409 für Race verwendet 🚫 BLOCKED

**Angriff**: Angreifer liest `current_version` aus 409 und sendet sofort, um mit dem legitimen Neuversuch zu rasen.
**Ergebnis**: BLOCKED — Das atomare `WHERE version = $current`-UPDATE bedeutet, dass nur ein gleichzeitiger Schreiber pro Version erfolgreich sein kann. Der andere erhält erneut 409. Dies ist das beabsichtigte optimistische Sperrverhalten.

---

### ATK-07 — Überlauf-Versionsnummer 🚫 BLOCKED

**Angriff**: `"version": 9999999999999999999` senden, um int zu überlaufen.
**Ergebnis**: BLOCKED — Große JSON-Integer können in PHP als Float dekodiert werden; `is_int()` gibt false zurück. Gibt 400 zurück.

---

### ATK-08 — Null-Version 🚫 BLOCKED

**Angriff**: `"version": 0` senden, um unter die Mindestversion zu gehen.
**Ergebnis**: BLOCKED — Version beginnt bei 1. `0 !== 1` → 409 Conflict.

---

### ATK-09 — Gefälschte current_version im Request-Body 🚫 BLOCKED

**Angriff**: Angreifer fügt `"current_version": 999` in den PATCH-Body ein in der Hoffnung, der Server verwende ihn.
**Ergebnis**: BLOCKED — `current_version` ist nur in der *Antwort*. Server ignoriert unbekannte Request-Felder; Version wird nur aus `$body['version']` entnommen.

---

### ATK-10 — SQL-Injection via version-Feld 🚫 BLOCKED

**Angriff**: `"version": "1; DROP TABLE articles; --"`.
**Ergebnis**: BLOCKED — Bei `is_int()`-Prüfung vor dem Erreichen der DB abgelehnt. Gibt 400 zurück.

---

### ATK-11 — Erfolgreiche Version wiederholen 🚫 BLOCKED

**Angriff**: Erfolgreichen PATCH (Version N → N+1) aufzeichnen, dann dieselbe Anfrage wiederholen.
**Ergebnis**: BLOCKED — Nach dem Update steht der Artikel bei Version N+1. Wiederholung von `version: N` gibt 409 zurück.

---

### ATK-12 — Gleichzeitige Schreibvorgänge beide erfolgreich 🚫 BLOCKED

**Angriff**: Zwei identische PATCH-Anfragen gleichzeitig mit derselben `version` senden.
**Ergebnis**: BLOCKED — `UPDATE … WHERE version = ?` ist atomar. DB serialisiert gleichzeitige Schreibvorgänge; zweites UPDATE trifft 0 Zeilen → Anwendung erkennt und gibt 409 zurück.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | Versions-Brute-Force | ✅ SAFE (Autorisierungsbedenken) |
| ATK-02 | String-Versions-Bypass | 🚫 BLOCKED |
| ATK-03 | Float-Version | 🚫 BLOCKED |
| ATK-04 | Fehlende Version — Blindes Schreiben | 🚫 BLOCKED |
| ATK-05 | Negative Version | 🚫 BLOCKED |
| ATK-06 | current_version Race-Exploit | 🚫 BLOCKED |
| ATK-07 | Überlauf-Version | 🚫 BLOCKED |
| ATK-08 | Null-Version | 🚫 BLOCKED |
| ATK-09 | Gefälschte current_version im Body | 🚫 BLOCKED |
| ATK-10 | SQL-Injection via Version | 🚫 BLOCKED |
| ATK-11 | Erfolgreiche Version wiederholen | 🚫 BLOCKED |
| ATK-12 | Gleichzeitige Schreibvorgänge beide erfolgreich | 🚫 BLOCKED |

**11 BLOCKED, 1 SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `"version": "1"` (String) akzeptieren | PHP-loser Vergleich `"1" == 1` ist true; Typverwirrungsangriff |
| `current_version` aus 409 weglassen | Client muss extra GET machen; höhere Latenz, mehr Anfragen bei Konflikt |
| Nur anwendungsseitige Prüfung verwenden (keine WHERE-Klausel) | Race Condition zwischen Versions-Lesen und Schreiben |
| 200 bei fehlender Version zurückgeben | Unbedingtes Überschreiben — verlorenes Update |
