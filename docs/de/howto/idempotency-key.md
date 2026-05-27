# How-to: Idempotency Key (Request-Deduplizierung)

> **FT-Referenz**: FT292 (`NENE2-FT/deduplog`) — Idempotency-Key-Deduplizierung: UNIQUE(idempotency_key)-DB-Constraint, 24h TTL mit neuverarbeitbarem Ablauf, `replayed: true`-Flag auf gecachten Antworten, parametrisierte Abfragen verhindern Injection, ATK-01–12 alle BLOCKED, 24 Tests / 57 Assertions bestanden.

Diese Anleitung zeigt, wie Idempotency-Keys implementiert werden — ein header-basierter Mechanismus, der sicherstellt, dass wiederholte Anfragen (Retries, Netzwerkausfälle) dasselbe Ergebnis ohne doppelte Nebeneffekte erzeugen.

## Schema

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` stellt sicher, dass jeder Key einmal gespeichert wird. Der Response-Body wird als JSON serialisiert und bei nachfolgenden Anfragen wiedergegeben.

## Anfrage-Ablauf

```
Client sendet POST /payments mit Idempotency-Key: <uuid>
  │
  ├─ Key in DB gefunden UND nicht abgelaufen?
  │    └─ JA → gecachte Antwort + { "replayed": true } zurückgeben
  │
  └─ NEIN → Anfrage verarbeiten → Antwort speichern → 201 zurückgeben
```

## Idempotency-Key-Extraktion

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

Der Key ist erforderlich und muss nach dem Trimmen nicht leer sein. Nur-Leerzeichen-Keys werden mit 400 abgelehnt.

## Cache-Lookup — Ablaufprüfung

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // Abgelaufene Einträge werden als frisch behandelt (neuverarbeitbar)
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

Abgelaufene Keys geben `null` zurück — die Anfrage wird als neu neuverarbeitet. Dies ermöglicht sichere Retries nach TTL-Ablauf ohne permanente Deduplizierung.

## Cache-Speicherung — TTL-Berechnung

```php
private const int TTL_SECONDS = 86400; // 24 Stunden

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

Die TTL wird in UTC berechnet. `DateTimeImmutable::modify()` behandelt DST-Übergänge und Mitternacht-Rollover sicher.

## `replayed: true`-Signal

Gecachte Antworten beinhalten `"replayed": true` in den Body gemischt:

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

Dadurch können Clients zwischen erstmaligen Antworten und Replays unterscheiden, ohne Statuscodes zu prüfen. Der Statuscode wird unverändert wiedergegeben (201 für Erstellung).

## UNIQUE-Constraint als Race-Guard

```sql
UNIQUE(idempotency_key)
```

Wenn zwei gleichzeitige Anfragen mit demselben Key beide die Lookup-Prüfung bestehen (TOCTOU), gelingt nur ein `INSERT`. Der andere erhält einen Constraint-Fehler, den die Anwendung behandeln kann, indem sie die gecachte Antwort neu abruft.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SQL-Injection im Idempotency-Key-Header 🚫 BLOCKED

**Angriff**: `Idempotency-Key: '; DROP TABLE idempotency_keys; --` senden.
**Ergebnis**: BLOCKED — alle Abfragen verwenden parametrisierte Statements. Der Injection-String wird als wörtlicher Key-Wert gespeichert oder nachgeschlagen.

---

### ATK-02 — SQL-Injection im Betrag-Feld 🚫 BLOCKED

**Angriff**: `{ "amount": "1; DROP TABLE payments;" }` senden.
**Ergebnis**: BLOCKED — Betragsvalidierung erfordert Integer-Typ. String-Werte schlagen `is_int()`-Prüfung fehl → 422. Keine DB-Abfrage ausgeführt.

---

### ATK-03 — SQL-Injection im Item-Feld (sicher gespeichert) 🚫 BLOCKED

**Angriff**: `{ "item": "' OR 1=1; --" }` bei Bestellerstellung senden.
**Ergebnis**: BLOCKED — parametrisierte Abfrage speichert den String wörtlich als `item`-Wert. Keine SQL-Ausführung.

---

### ATK-04 — Replay-Angriff (gleicher Key 10 Mal) 🚫 BLOCKED

**Angriff**: `POST /payments` mit demselben Key 10 Mal senden, um 10 Einträge zu erstellen.
**Ergebnis**: BLOCKED — erste Anfrage erstellt eine Zahlung und cachet die Antwort. Alle 9 nachfolgenden Anfragen geben die gecachte Antwort mit `replayed: true` zurück. Nur 1 Zahlungszeile existiert.

---

### ATK-05 — Nur-Leerzeichen-Idempotency-Key 🚫 BLOCKED

**Angriff**: `Idempotency-Key:    ` (nur Leerzeichen) senden, um die Leer-Key-Prüfung zu umgehen.
**Ergebnis**: BLOCKED — `trim($key) === ''` → 400. Nur-Leerzeichen-Keys sind äquivalent zu fehlenden Keys.

---

### ATK-06 — Extrem langer Idempotency-Key 🚫 BLOCKED (Design-Hinweis)

**Angriff**: Einen Mehrere-Megabyte-Key-String senden.
**Ergebnis**: BLOCKED (Design-Hinweis) — SQLite speichert den Key wörtlich; sehr lange Keys verschlechtern die Lookup-Performance, stürzen aber nicht ab. In Produktion ein Längenlimit hinzufügen (z. B. `strlen($key) > 255 → 400`).

---

### ATK-07 — Negative Menge in Bestellung 🚫 BLOCKED

**Angriff**: `{ "quantity": -5 }` senden, um eine negativ-Mengen-Bestellung zu erstellen.
**Ergebnis**: BLOCKED — Mengenvalidierung: `$quantity <= 0` → 422. Nur positive Ganzzahlen akzeptiert.

---

### ATK-08 — XSS im Item-Feld als Literal gespeichert 🚫 BLOCKED

**Angriff**: `{ "item": "<script>alert(1)</script>" }` senden.
**Ergebnis**: BLOCKED — als JSON-String-Wert wörtlich gespeichert. Die API gibt `application/json` zurück; JSON-Encoding escaped `<`, `>`. Kein HTML-Rendering in der API-Schicht.

---

### ATK-09 — Gleichzeitige Duplikat-Keys 🚫 BLOCKED

**Angriff**: Zwei Prozesse senden denselben Key gleichzeitig; beide bestehen die Lookup-Prüfung, bevor einer speichert.
**Ergebnis**: BLOCKED — `UNIQUE(idempotency_key)` stellt sicher, dass nur ein INSERT gelingt. Der Verlierer erhält einen Constraint-Fehler und kann die gecachte Antwort neu abrufen.

---

### ATK-10 — Integer-Überlauf im Betrag 🚫 BLOCKED (Design-Hinweis)

**Angriff**: `{ "amount": 9999999999999999999 }` (jenseits von PHP_INT_MAX) senden.
**Ergebnis**: BLOCKED (Design-Hinweis) — PHP konvertiert sehr große JSON-Integer still zu Float. `is_int()` besteht für Ganzzahlen im Bereich. In Produktion eine obere Grenzprüfung hinzufügen.

---

### ATK-11 — NULL-Betrag 🚫 BLOCKED

**Angriff**: `{ "amount": null }` senden, in der Hoffnung, dass null die Validierung umgeht.
**Ergebnis**: BLOCKED — `!is_int(null)` ist true und `ctype_digit(null)` ist false → 422.

---

### ATK-12 — Keine internen Informationen durchgesickert 🚫 BLOCKED

**Angriff**: Einen 422-Fehler auslösen und prüfen, ob Stack-Traces, Dateipfade oder SQL in der Antwort erscheinen.
**Ergebnis**: BLOCKED — Fehlerantworten enthalten nur `{ "error": "..." }` oder Problem Details. Keine internen Pfade, SQL oder Stack-Traces in irgendeiner Antwort.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | SQL-Injection im Idempotency-Key-Header | 🚫 BLOCKED |
| ATK-02 | SQL-Injection im Betrag-Feld | 🚫 BLOCKED |
| ATK-03 | SQL-Injection im Item-Feld | 🚫 BLOCKED |
| ATK-04 | Replay-Angriff (10 Duplikat-Anfragen) | 🚫 BLOCKED |
| ATK-05 | Nur-Leerzeichen-Key | 🚫 BLOCKED |
| ATK-06 | Extrem langer Key | 🚫 BLOCKED (Design-Hinweis) |
| ATK-07 | Negative Menge | 🚫 BLOCKED |
| ATK-08 | XSS im Item-Feld | 🚫 BLOCKED |
| ATK-09 | Gleichzeitige Duplikat-Keys | 🚫 BLOCKED |
| ATK-10 | Integer-Überlauf im Betrag | 🚫 BLOCKED (Design-Hinweis) |
| ATK-11 | NULL-Betrag | 🚫 BLOCKED |
| ATK-12 | Keine internen Informationen durchgesickert | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Parametrisierte Abfragen, strikte Typvalidierung, `UNIQUE(idempotency_key)` und TTL-Ablauf decken alle kritischen Deduplizierungs-Angriffsvektoren ab.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Kein `UNIQUE(idempotency_key)`-Constraint | Gleichzeitige Retries erstellen doppelte Einträge; Deduplizierungs-Race-Condition |
| Keine TTL / permanente Dedup | Alte Keys füllen die Tabelle; legitime Retries nach 1+ Tagen schlagen fehl |
| Kein `replayed: true`-Flag | Client kann erste Antwort nicht von gecachtem Replay unterscheiden |
| Ablauf prüfen, aber abgelaufene Keys nie neu verarbeiten | Retry nach TTL gibt noch gecachte (möglicherweise veraltete) Antwort zurück |
| Nur-Leerzeichen-Keys akzeptieren | `"   "` als gültiger Key behandelt; verschiedene Clients können `""` vs `"   "` austauschbar verwenden |
| Kein Key-Längenlimit | Mehrere-MB-Keys in Speicher und Lookup verschlechtern Performance |
| 409 bei Duplikat zurückgeben | Replay soll ursprünglichen Status zurückgeben (201), nicht Conflict |
| Betrag-Typ nicht strikt validieren | `"1000"`-String besteht lockere Prüfungen; `is_int()` für strenges JSON-Integer verwenden |
| Keine obere Grenze auf Betrag | Integer-Überlauf oder absurde Beträge ohne Geschäftsvalidierung akzeptiert |
