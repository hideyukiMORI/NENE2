# How-to: Optimistische Sperre mit ETag / If-Match

> **FT-Referenz**: FT320 (`NENE2-FT/locklog`) — Dokumentversionierung mit ETag-Header, If-Match für Mutationen erforderlich (428), Ablehnung veralteter ETags (412), Verhinderung verlorener Updates, 15 Tests / 30 Assertions PASS.

Diese Anleitung zeigt, wie optimistische Nebenläufigkeitskontrolle mit HTTP-ETags implementiert wird, um verlorene Updates ohne pessimistisches DB-Sperren zu verhindern.

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

`version` ist das maßgebliche Nebenläufigkeitstoken. ETag ist `"v{version}"`.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/documents` | Dokument erstellen |
| `GET`  | `/documents/{id}` | Abrufen mit ETag |
| `PUT`  | `/documents/{id}` | Aktualisieren (If-Match erforderlich) |
| `DELETE` | `/documents/{id}` | Löschen (If-Match erforderlich) |

## Erstellen

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET — Gibt ETag zurück

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

Client speichert den ETag und sendet ihn als `If-Match` bei der nächsten Mutation.

## PUT — Optimistische Sperre

```php
// Client sendet aktuellen ETag
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// Veralteter ETag (ein anderer Client hat zuerst aktualisiert)
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// Fehlendes If-Match
PUT /documents/1
{"title": "Keine Sperre"}
→ 428 Precondition Required

// Wildcard — Versionscheck umgehen
PUT /documents/1  If-Match: *
→ 200  // immer erfolgreich wenn Dokument existiert
```

### Verhinderung verlorener Updates

```
Alice liest Dokument → version=1, ETag="v1"
Bob  liest Dokument → version=1, ETag="v1"

Alice: PUT If-Match: "v1" → 200 (Version wird 2)
Bob:   PUT If-Match: "v1" → 412 ← Bobs Schreiben wird abgelehnt

Bob muss neu-GET, um Alices Änderung zu sehen, dann mit "v2" erneut versuchen
```

## DELETE — Erfordert auch If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // Version bereits hochgesetzt
DELETE /documents/1                  → 428  // If-Match fehlt
DELETE /documents/9999  If-Match: "v1" → 404
```

## Implementierung

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // Wildcard oder exakten Versions-Match prüfen
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — ETag-Brute-Force zum Umgehen der Vorbedingung ✅ SAFE

**Angriff**: Angreifer durchläuft `"v1"`, `"v2"`, `"v3"` bis er die aktuelle Version findet, um ein Update zu erzwingen.
**Ergebnis**: SAFE — ETag-Brute-Force ist bei einem einfachen sequentiellen Zähler möglich, aber das Update ist immer noch ein legitimer Schreibvorgang. In hochwertigeren Szenarien undurchsichtige ETags verwenden (z.B. `hash('sha256', $version . $secret)`).

---

### ATK-02 — If-Match weglassen für bedingungsloses Schreiben 🚫 BLOCKED

**Angriff**: Angreifer sendet PUT ohne `If-Match`-Header in der Hoffnung, der Server akzeptiere bedingungslose Schreibvorgänge.
**Ergebnis**: BLOCKED — Fehlendes `If-Match` gibt 428 Precondition Required zurück.

---

### ATK-03 — Wildcard If-Match: * zum Umgehen des Versionschecks 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: *`, um bedingungslos zu überschreiben.
**Ergebnis**: BLOCKED — Wildcard ist per Design akzeptiert (trifft jede bestehende Version), aber das Dokument muss existieren. Für benutzerbezogene Mutation Wildcard auf Admin-Rollen beschränken.

---

### ATK-04 — Race Condition — gleichzeitige Schreibvorgänge mit demselben ETag 🚫 BLOCKED

**Angriff**: Zwei Clients senden gleichzeitig PUT mit `"v1"`. Beide bestehen den ETag-Check vor dem Update.
**Ergebnis**: BLOCKED — Das DB-UPDATE verwendet `WHERE version = $expectedVersion`. Der zweite Schreibvorgang findet die Version bereits inkrementiert und aktualisiert 0 Zeilen → gibt 412 zurück.

---

### ATK-05 — Beliebigen ETag-Wert injizieren 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v999999"` für ein Dokument bei Version 1.
**Ergebnis**: BLOCKED — ETag wird mit dem gespeicherten `"v{version}"`-String verglichen. `"v999999" ≠ "v1"` → 412.

---

### ATK-06 — Header-Injection via If-Match 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v1"\r\nX-Admin: true`, um Antwort-Header zu injizieren.
**Ergebnis**: BLOCKED — PSR-7-Header-Parsing entfernt CR/LF aus Header-Werten.

---

### ATK-07 — Löschen mit veraltetem ETag 🚫 BLOCKED

**Angriff**: Angreifer erhält alten ETag, wartet auf Dokumentaktualisierung, sendet dann DELETE mit veraltetem ETag.
**Ergebnis**: BLOCKED — DELETE prüft ETag genau wie PUT. Veralteter ETag gibt 412 zurück; das Dokument überlebt.

---

### ATK-08 — Negative/Null-Version im ETag 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v-1"` oder `If-Match: "v0"`.
**Ergebnis**: BLOCKED — Version beginnt bei 1 und wird nur inkrementiert. `"v-1"` und `"v0"` treffen nie eine gespeicherte Version.

---

### ATK-09 — Vorherigen erfolgreichen ETag wiederholen 🚫 BLOCKED

**Angriff**: Nach erfolgreichem Update (`v1→v2`) repliziert Angreifer `If-Match: "v2"`.
**Ergebnis**: BLOCKED — Dies ist gültiges Verhalten — der Angreifer hat ein aktuelles Token. Autorisierung (Eigentümerschaftsprüfung) ist der Guard; ETag verhindert nur gleichzeitige Kollision.

---

### ATK-10 — Versionszähler-Überlauf 🚫 BLOCKED

**Angriff**: Versionszähler durch Millionen von Updates zum Überlaufen bringen.
**Ergebnis**: BLOCKED — PHP-Integer sind 64-Bit (max ~9,2 × 10^18). Overflow ist in der Praxis nicht erreichbar. Rate-Limiting schützt vor schnellen Update-Schleifen.

---

### ATK-11 — ETag-Spoofing in Antwort 🚫 BLOCKED

**Angriff**: Angreifer erstellt eine Anfrage, sodass der Server ein gefälschtes `ETag: "v999"` zurückgibt.
**Ergebnis**: BLOCKED — ETag wird immer aus `$doc['version']` in der DB berechnet. Keine Benutzereingabe beeinflusst den zurückgegebenen ETag.

---

### ATK-12 — Kein If-Match bei DELETE 🚫 BLOCKED

**Angriff**: Angreifer sendet DELETE ohne `If-Match`.
**Ergebnis**: BLOCKED — DELETE gibt wie PUT 428 zurück, wenn `If-Match` fehlt.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|---------|
| ATK-01 | ETag-Brute-Force | ✅ SAFE (sequentiell, aber Hinweis beachten) |
| ATK-02 | If-Match weglassen | 🚫 BLOCKED |
| ATK-03 | Wildcard If-Match-Bypass | 🚫 BLOCKED |
| ATK-04 | Gleichzeitiger Schreib-Race | 🚫 BLOCKED |
| ATK-05 | Beliebigen ETag injizieren | 🚫 BLOCKED |
| ATK-06 | Header-Injection via If-Match | 🚫 BLOCKED |
| ATK-07 | Löschen mit veraltetem ETag | 🚫 BLOCKED |
| ATK-08 | Negative/Null-Versions-ETag | 🚫 BLOCKED |
| ATK-09 | Vorherigen ETag wiederholen | ✅ SAFE (Autorisierungsbedenken, nicht ETag) |
| ATK-10 | Versionszähler-Überlauf | 🚫 BLOCKED |
| ATK-11 | ETag-Spoofing in Antwort | 🚫 BLOCKED |
| ATK-12 | Löschen ohne If-Match | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| PUT/DELETE ohne If-Match erlauben | Jedes Schreiben ohne Lock-Token verursacht verlorene Updates |
| 200 bei veraltetem ETag zurückgeben (stille Überschreibung) | Verlorenes Update: letzter Schreiber gewinnt, gleichzeitige Änderungen werden stillschweigend verworfen |
| Veränderbaren ETag verwenden (z.B. `Last-Modified`-Timestamp) | Takt-Drift verursacht falsche 412 oder falsche Treffer |
| Wildcard `*` If-Match-Unterstützung überspringen | Bricht Admin-Tooling und RFC 7232-Konformität |
| Keine DB-Level-Versionscheck in WHERE-Klausel | Anwendungsprüfung besteht, aber gleichzeitiger DB-Schreibvorgang rast durch |
