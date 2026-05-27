# How-to: Event-Analytics-API

> **FT-Referenz**: FT243 (`NENE2-FT/statslog`) — Event-Analytics-API
> **VULN**: FT243 — Schwachstellenbewertung (V-01 bis V-10)

Demonstriert eine Event-Ingestion- und Aggregations-API, bei der rohe Analytics-Events
mit einem JSON-`properties`-Blob aufgezeichnet, mit SQLite `json_extract()` abgefragt und
in Pro-Tag-/Pro-Typ-/Unique-User-Statistiken aggregiert werden. Enthält eine vollständige
Schwachstellenbewertung des nicht-authentifizierten Designs.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------------------------|------------------------------------------------------|
| `POST` | `/events`              | Ein Analytics-Event aufzeichnen |
| `GET`  | `/events`              | Events auflisten (paginiert) |
| `GET`  | `/events/by-property`  | Events nach JSON-Property-Schlüssel+Wert filtern |
| `GET`  | `/events/{id}`         | Ein einzelnes Event abrufen |
| `GET`  | `/stats/per-day`       | Event-Anzahl nach Tag gruppiert |
| `GET`  | `/stats/per-type`      | Event-Anzahl nach Event-Typ gruppiert |
| `GET`  | `/stats/unique-users`  | Anzahl einzigartiger Benutzer nach Tag gruppiert |

> **Statische Routen vor parametrisierten**: `/events/by-property` wird vor
> `/events/{id}` registriert, damit der Router den Literalpfad korrekt dispatcht.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` wird als JSON-String (`TEXT`) gespeichert. SQLites `json_extract()` ermöglicht
das Abfragen des Blobs zur Lesezeit ohne ein separates Schema. Drei Indizes decken die
häufigsten Zugriffsmuster ab: nach Typ, nach Zeitraum und nach Benutzer.

---

## Event-Erstellung: JSON-Properties-Blob

`POST /events` akzeptiert ein flexibles `properties`-Objekt neben den erforderlichen `event_type`
und `user_id`:

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` muss ein JSON-Objekt sein (`is_array()`-Prüfung) — skalare Werte fallen auf `'{}'` zurück.
- `occurred_at` wird vom Aufrufer angegeben oder standardmäßig auf jetzt gesetzt — keine serverseitige Erzwingung, dass es in einem gültigen Bereich liegt.
- `JSON_THROW_ON_ERROR` stellt sicher, dass fehlerhaftes Zwischen-JSON sofort wirft anstatt `false` zu erzeugen.

Deserialisierung beim Lesen:
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## JSON-Property-Suche mit `json_extract()`

`GET /events/by-property?key=page&value=/home` filtert Events nach einem Property-Schlüssel/Wert:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` extrahiert das `page`-Feld aus dem JSON-Blob.
Der Pfad `'$.' . $propertyKey` wird durch Konkatenation konstruiert, **nicht** als Pfad selbst parametrisiert
— SQLites `json_extract()` akzeptiert nur einen Literal-Pfad-String, keinen gebundenen Parameter für den Pfadausdruck. Der Schlüssel kommt aus einem Query-String, wird aber nicht weiter validiert (siehe V-05).

`= ?` vergleicht den extrahierten Wert mit dem bereitgestellten `$propertyValue` als parametrisierten
Binding — SQL-Injection über den Wert ist blockiert. Die Pfadkonkatenation ist die zu prüfende Grenze.

---

## Aggregationsabfragen

### Pro-Tag-Event-Anzahl

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` kürzt den Zeitstempel auf ein Datum. `GROUP BY`
auf demselben Ausdruck gruppiert alle Events desselben Tages. Beide `$from` und
`$to` sind parametrisiert — keine String-Konkatenation in das SQL.

### Pro-Typ-Event-Anzahl

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` zeigt die häufigsten Event-Typen zuerst.

### Unique User pro Tag

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` zählt jede `user_id` nur einmal pro Tag.

### Standard-Datumsbereich

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

Breite Standardwerte (`2000-01-01` bis `2100-01-01`) stellen sicher, dass Statistiken ohne Datumsbereich
alle Events enthalten. In der Produktion den Standardbereich auf ein sinnvolles Fenster (z. B. letzte 30
Tage) begrenzen, um Full-Table-Scans auf großen Datensätzen zu vermeiden.

---

## VULN — Schwachstellenbewertung (FT243)

### V-01 — Keine Authentifizierung: Jeder kann Events aufzeichnen

**Risiko**: Jeder Aufrufer kann Events mit beliebigen `event_type` und `user_id` senden. Es gibt
keine API-Schlüssel-, Sitzungs- oder Token-Prüfung.

**Auswirkung**: Ein Angreifer kann den Analytics-Datensatz mit Millionen gefälschter Events verseuchen,
Statistiken verzerren und jede Benutzer-ID imitieren.

**Urteil**: **EXPOSED** — API-Schlüssel oder JWT-Authentifizierung für den Schreibendpunkt hinzufügen.
Read-only-Statistiken können öffentlich bleiben, aber die Aufnahme muss authentifiziert sein.

---

### V-02 — Keine Autorisierung für Statistiken: Statistiken sind weltweit lesbar

**Risiko**: `GET /stats/per-day`, `/stats/per-type`, `/stats/unique-users` geben
aggregierte Daten ohne Authentifizierung zurück.

**Auswirkung**: Wettbewerber oder Crawler können Produktnutzungstrends, täglich aktive Benutzer
und Feature-Adoption überwachen.

**Urteil**: **EXPOSED** — Statistik-Endpunkte auf authentifizierte Rollen beschränken (Admin,
Analytics-Betrachter). Wenn Statistiken absichtlich öffentlich sind, dies als Designentscheidung dokumentieren.

---

### V-03 — `user_id` ist vom Benutzer angegeben: keine Identitätsverifikation

**Risiko**: `user_id` wird direkt aus dem Request-Body ohne Beweis genommen, dass der
Aufrufer diese Identität besitzt.

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**Auswirkung**: Ein Angreifer kann Aktivitäten für jede Benutzer-ID fälschen und Pro-Benutzer-
Statistiken sowie Unique-User-Zählungen manipulieren.

**Urteil**: **EXPOSED** — in authentifizierten Kontexten `user_id` aus der verifizierten Identität
im Token/Sitzung ableiten, nie aus dem Request-Body.

---

### V-04 — `occurred_at` ist vom Benutzer angegeben: Rückdatierung und Zukunftsdatierung von Events

**Risiko**: Das Feld `occurred_at` wird ohne Bereichsvalidierung vom Aufrufer akzeptiert.

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**Auswirkung**: Angreifer können Events in beliebige historische Zeitslots einfügen (rückdatieren) oder
weit in die Zukunft, was Zeitreihenstatistiken verzerrt.

**Urteil**: **EXPOSED** — validieren, dass `occurred_at` in einem akzeptablen Fenster liegt
(z. B. letzte 24 Stunden bis +5 Minuten) und Zeitstempel außerhalb des Bereichs ablehnen.

---

### V-05 — `json_extract()`-Pfadkonkatenation: JSON-Pfad-Injection

**Risiko**: Der Property-Schlüssel wird direkt in den JSON-Pfadausdruck konkateniert:
`'$.' . $propertyKey`. Es gibt keine Validierung, dass `$propertyKey` ein sicherer Bezeichner ist.

**Angriff**:
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
Wird zu: `json_extract(properties, '$.x"] OR 1=1 --')` — SQLite interpretiert das Pfadargument
als String-Literal, der an `json_extract` übergeben wird, nicht als SQL. Der Pfad wird nicht als SQL ausgeführt — er wird von SQLites JSON-Funktionen als String behandelt. Ungültige Pfade geben `NULL` zurück, daher gibt die Abfrage keine statt aller Zeilen zurück.

**Beobachtet**: `json_extract()` behandelt das gesamte zweite Argument als Pfadausdruck.
Fehlerhafte Pfade (`$.x"] OR 1=1 --`) geben für jede Zeile `NULL` zurück — keine SQL-Injection.
Das Verhalten hängt jedoch von SQLites JSON-Implementierung ab — ein Defense-in-Depth-Ansatz würde `$propertyKey` mit `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` validieren.

**Urteil**: **PARTIALLY BLOCKED** — SQLites `json_extract()` schirmt das Pfadargument ab.
Explizite Schlüsselvalidierung (`[a-zA-Z_][a-zA-Z0-9_]*`) für Defense in Depth hinzufügen.

---

### V-06 — Unbeschränkter event_type: keine Allowlist

**Risiko**: `event_type` akzeptiert jeden nicht-leeren String. Sehr lange Strings oder
High-Cardinality-Typen blähen das `countPerType`-Ergebnis auf.

```json
{"event_type": "aaaa....(10000 Zeichen)", "user_id": "x"}
```

**Auswirkung**: Unbeschränkte Kardinalität in `GROUP BY event_type` kann Speicherdruck verursachen.
Speicheraufblähung durch sehr lange Strings.

**Urteil**: **EXPOSED** — Längenbeschränkung hinzufügen (z. B. 100 Zeichen) und optional
eine Event-Typ-Allowlist.

---

### V-07 — SQL-Injection über `from`/`to`-Datumparameter

**Angriff**: SQL-Metazeichen im Datumsbereich übergeben.

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**Beobachtet**: Beide `$from` und `$to` werden als parametrisierte Werte gebunden (`?`-Platzhalter).
Die SQL-Engine behandelt sie als Literal-Strings, nicht als SQL-Fragmente.

**Urteil**: **BLOCKED** — parametrisierte Abfragen verhindern SQL-Injection über Datumparameter.

---

### V-08 — Properties-Größe: keine Begrenzung für JSON-Blob

**Risiko**: `properties` wird als `TEXT` ohne Größenvalidierung gespeichert. Ein Angreifer kann
mehrmegabyte-große JSON-Objekte senden.

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1MB)"}}
```

**Auswirkung**: Jedes große Event verbraucht erheblichen Speicherplatz. Masseneinspielung großer Events
kann den Festplattenplatz erschöpfen.

**Urteil**: **EXPOSED** — Größenprüfung für den rohen `properties`-Wert hinzufügen
(z. B. `strlen($raw) > 65535 → 422`). Request-Größen-Middleware als äußere Grenze verwenden.

---

### V-09 — Event-Flut: kein Rate-Limiting bei POST /events

**Risiko**: Es gibt kein Rate-Limiting am Ingestion-Endpunkt.

**Auswirkung**: Ein einzelner Client kann Millionen Events pro Sekunde senden und dabei
Datenbank und Speicher überlasten.

**Urteil**: **EXPOSED** — `ThrottleMiddleware` oder Per-IP-/Per-API-Key-Rate-Limiting
am Schreibendpunkt anwenden.

---

### V-10 — Statistik-Offenlegung: `COUNT(DISTINCT user_id)` gibt Benutzeranzahl preis

**Risiko**: `GET /stats/unique-users` gibt die Anzahl der verschiedenen Benutzer-IDs pro Tag zurück.

**Auswirkung**: Ohne Authentifizierung gibt dies täglich aktive Benutzeranzahlen preis — eine sensible
Geschäftskennzahl.

**Urteil**: **EXPOSED** (gleiche Ursache wie V-02). Statistik-Endpunkte einschränken oder authentifizieren.

---

## VULN-Zusammenfassung

| # | Schwachstelle | Urteil |
|---|---------------|---------|
| V-01 | Keine Authentifizierung am Schreibendpunkt | EXPOSED |
| V-02 | Statistik-Endpunkte weltweit lesbar | EXPOSED |
| V-03 | `user_id` nicht verifiziert (Identitätsfälschung) | EXPOSED |
| V-04 | `occurred_at` vom Benutzer angegeben (Rück-/Zukunftsdatierung) | EXPOSED |
| V-05 | `json_extract()`-Pfadkonkatenation | PARTIALLY BLOCKED |
| V-06 | `event_type` keine Allowlist / Längenbegrenzung | EXPOSED |
| V-07 | SQL-Injection über Datumsbereich-Parameter | BLOCKED |
| V-08 | Keine Größenbegrenzung für `properties`-JSON-Blob | EXPOSED |
| V-09 | Kein Rate-Limiting bei POST /events | EXPOSED |
| V-10 | Unique-User-Anzahl gibt DAU-Kennzahlen preis | EXPOSED |

**Kritische Korrekturen vor der Produktion**:
1. **V-01 / V-02 / V-10** — Authentifizierung (API-Schlüssel oder JWT) für Schreib- und Statistik-Endpunkte hinzufügen
2. **V-03** — `user_id` aus verifizierter Identität ableiten, nicht aus dem Request-Body
3. **V-04** — Validieren, dass `occurred_at` in einem akzeptablen Zeitfenster liegt
4. **V-05** — `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)`-Validierung hinzufügen
5. **V-06** — `event_type`-Maximallänge hinzufügen (z. B. 100 Zeichen)
6. **V-08** — `properties`-Größenbegrenzung hinzufügen (z. B. 64 KB)
7. **V-09** — Rate-Limiting bei POST /events anwenden

---

## Verwandte Anleitungen

- [`event-sourcing.md`](event-sourcing.md) — unveränderliches Event-Log-Muster
- [`api-usage-metering.md`](api-usage-metering.md) — gemessene API mit Kontingentdurchsetzung
- [`quota-management.md`](quota-management.md) — Pro-Ressource-Kontingent mit QuotaWindow
- [`cursor-pagination.md`](cursor-pagination.md) — effiziente Paginierung für hochvolumige Event-Feeds
