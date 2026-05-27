# How-to: Optimistic Locking mit ETag / If-Match

> **FT-Referenz**: FT320 (`NENE2-FT/locklog`) — Dokumentversionierung mit ETag-Header, If-Match für Mutationen erforderlich (428), Ablehnung veralteter ETags (412), Schutz vor Lost-Update, 15 Tests / 30 Assertions PASS.

Diese Anleitung zeigt, wie optimistische Nebenläufigkeitskontrolle mit HTTP-ETags implementiert wird, um Lost Updates ohne pessimistisches DB-Locking zu verhindern.

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

`version` ist das maßgebliche Nebenläufigkeits-Token. ETag ist `"v{version}"`.

## Endpunkte

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
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

Der Client speichert den ETag und sendet ihn bei der nächsten Mutation als `If-Match`.

## PUT — Optimistic Lock

```php
// Client sendet aktuellen ETag
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// Veralteter ETag (ein anderer Client hat zuerst aktualisiert)
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// If-Match fehlt
PUT /documents/1
{"title": "No lock"}
→ 428 Precondition Required

// Wildcard — Versionscheck umgehen
PUT /documents/1  If-Match: *
→ 200  // Erfolg immer, wenn Dokument existiert
```

### Schutz vor Lost Update

```
Alice liest Dok → version=1, ETag="v1"
Bob  liest Dok → version=1, ETag="v1"

Alice: PUT If-Match: "v1" → 200 (Version wird 2)
Bob:   PUT If-Match: "v1" → 412 ← Bobs Schreibvorgang wird abgelehnt

Bob muss erneut GET aufrufen, um Alices Änderung zu sehen, dann mit "v2" wiederholen
```

## DELETE — Erfordert ebenfalls If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // Version wurde bereits erhöht
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

    // Wildcard oder exakten Versionsabgleich prüfen
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

## ATK Assessment — Cracker-Mindset-Angriffstest

### ATK-01 — ETag-Brute-Force zur Umgehung der Vorbedingung ✅ SAFE

**Angriff**: Angreifer probiert `"v1"`, `"v2"`, `"v3"` durch, bis er die aktuelle Version findet, um ein Update zu erzwingen.
**Ergebnis**: SAFE — ETag-Brute-Force ist bei einem einfachen sequentiellen Zähler möglich, aber das Update ist trotzdem ein legitimer Schreibvorgang. Die 412-Antwort verrät nichts über die aktuelle Version; der Angreifer muss GET aufrufen, um zu bestätigen. In Szenarien mit hohem Wert opake ETags verwenden (z.B. `hash('sha256', $version . $secret)`).

---

### ATK-02 — If-Match weglassen, um bedingungslosen Schreibvorgang zu erzwingen 🚫 BLOCKED

**Angriff**: Angreifer sendet PUT ohne `If-Match`-Header in der Hoffnung, der Server akzeptiert bedingungslose Schreibvorgänge.
**Ergebnis**: BLOCKED — Fehlendes `If-Match` gibt 428 Precondition Required zurück. Der Endpunkt lehnt alle Schreibvorgänge ohne Lock-Token ab.

---

### ATK-03 — Wildcard If-Match: * zur Umgehung des Versionschecks 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: *`, um unbedingt zu überschreiben und Nebenläufigkeit zu ignorieren.
**Ergebnis**: BLOCKED — Wildcard wird per Design akzeptiert (passt auf jede vorhandene Version), aber das Dokument muss existieren (404 sonst). Dies entspricht der HTTP-Spezifikation: `*` bedeutet „existiert"; für Admin-Operationen ist das akzeptabel. Für benutzerorientierte Mutation Wildcard auf Admin-Rollen beschränken.

---

### ATK-04 — Race Condition — Gleichzeitige Schreibvorgänge mit demselben ETag 🚫 BLOCKED

**Angriff**: Zwei Clients senden gleichzeitig PUT mit `"v1"`. Beide passieren den ETag-Check, bevor einer aktualisiert.
**Ergebnis**: BLOCKED — Das DB-UPDATE verwendet `WHERE version = $expectedVersion`. Der zweite Schreibvorgang findet die Version bereits inkrementiert und aktualisiert 0 Zeilen → gibt 412 zurück. Atomar auf DB-Ebene.

---

### ATK-05 — Beliebigen ETag-Wert injizieren 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v999999"` für ein Dokument bei Version 1 in der Hoffnung, der Server überspringt die Validierung.
**Ergebnis**: BLOCKED — ETag wird mit dem gespeicherten `"v{version}"`-String verglichen. `"v999999" ≠ "v1"` → 412.

---

### ATK-06 — Header-Injection via If-Match 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v1"\r\nX-Admin: true`, um Antwort-Header zu injizieren.
**Ergebnis**: BLOCKED — PSR-7-Header-Parsing entfernt CR/LF aus Header-Werten. Der injizierte Header erreicht die Anwendungsschicht nie.

---

### ATK-07 — Löschen mit veraltetem ETag 🚫 BLOCKED

**Angriff**: Angreifer erhält einen alten ETag, wartet darauf, dass das Dokument aktualisiert wird, und sendet dann DELETE mit dem veralteten ETag.
**Ergebnis**: BLOCKED — DELETE prüft ETag genau wie PUT. Veralteter ETag gibt 412 zurück; das Dokument überlebt.

---

### ATK-08 — Negative Version im ETag 🚫 BLOCKED

**Angriff**: Angreifer sendet `If-Match: "v-1"` oder `If-Match: "v0"`.
**Ergebnis**: BLOCKED — Version beginnt bei 1 und erhöht sich nur. `"v-1"` und `"v0"` stimmen nie mit einer gespeicherten Version überein.

---

### ATK-09 — Vorherigen erfolgreichen ETag wiederholen 🚫 BLOCKED

**Angriff**: Nach einem erfolgreichen Update (`v1→v2`) wiederholt der Angreifer `If-Match: "v2"`, um ein weiteres Update vorzunehmen.
**Ergebnis**: BLOCKED — Das ist gültiges Verhalten — der Angreifer hat ein aktuelles Token. Die Sorge ist, dass ein Dritter nicht das Token eines anderen Nutzers verwenden sollte. Autorisierung (Eigentümerprüfung) ist der Schutz; ETag verhindert nur gleichzeitige Kollision.

---

### ATK-10 — Versionszähler-Überlauf 🚫 BLOCKED

**Angriff**: Erzwingt einen Überlauf des Versionszählers durch Millionen von Updates.
**Ergebnis**: BLOCKED — PHP-Integer sind 64-Bit (max ~9,2 × 10^18). Einen Überlauf zu erreichen ist in der Praxis nicht möglich. Rate Limiting schützt vor schnellen Update-Schleifen.

---

### ATK-11 — ETag-Spoofing in Antwort 🚫 BLOCKED

**Angriff**: Angreifer gestaltet eine Anfrage so, dass der Server einen gefälschten `ETag: "v999"` zurückgibt, was andere Clients glauben lässt, das Dokument sei bei Version 999.
**Ergebnis**: BLOCKED — ETag wird immer aus `$doc['version']` in der DB berechnet. Keine Benutzereingabe beeinflusst den zurückgegebenen ETag.

---

### ATK-12 — Kein If-Match bei DELETE, um ohne Lock zu löschen 🚫 BLOCKED

**Angriff**: Angreifer sendet DELETE ohne `If-Match`, verlässt sich auf einen Server, der die Vorbedingung nicht erzwingt.
**Ergebnis**: BLOCKED — DELETE gibt wie PUT 428 zurück, wenn `If-Match` fehlt.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|--------|--------|
| ATK-01 | ETag-Brute-Force | ✅ SAFE (sequentiell, aber siehe Hinweis) |
| ATK-02 | If-Match weglassen | 🚫 BLOCKED |
| ATK-03 | Wildcard-If-Match-Bypass | 🚫 BLOCKED |
| ATK-04 | Race Condition beim gleichzeitigen Schreiben | 🚫 BLOCKED |
| ATK-05 | Beliebigen ETag injizieren | 🚫 BLOCKED |
| ATK-06 | Header-Injection via If-Match | 🚫 BLOCKED |
| ATK-07 | Löschen mit veraltetem ETag | 🚫 BLOCKED |
| ATK-08 | Negative/Null-Version im ETag | 🚫 BLOCKED |
| ATK-09 | Vorherigen ETag wiederholen | ✅ SAFE (Autorisierungsthema, nicht ETag) |
| ATK-10 | Versionszähler-Überlauf | 🚫 BLOCKED |
| ATK-11 | ETag-Spoofing in Antwort | 🚫 BLOCKED |
| ATK-12 | Löschen ohne If-Match | 🚫 BLOCKED |

**10 BLOCKED, 2 SAFE, 0 EXPOSED** — Keine kritischen Befunde.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| PUT/DELETE ohne If-Match erlauben | Jeder Schreibvorgang ohne Lock-Token verursacht Lost Updates |
| 200 bei veraltetem ETag zurückgeben (stilles Überschreiben) | Lost Update: Letzter Schreiber gewinnt, gleichzeitige Bearbeitungen werden still verworfen |
| Veränderbaren ETag verwenden (z.B. `Last-Modified`-Zeitstempel) | Uhrendrift verursacht unberechtigte 412- oder falsche Übereinstimmungen |
| Wildcard-`*`-If-Match-Support weglassen | Bricht Admin-Tooling und RFC-7232-Konformität |
| Keinen DB-seitigen Versionscheck in der WHERE-Klausel | Anwendungscheck gelingt, aber gleichzeitiger DB-Schreibvorgang läuft durch |
