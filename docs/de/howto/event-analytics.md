# How-to: Event-Analytics-API

> **FT-Referenz**: FT51 (`NENE2-FT/statslog`) — Event-Analytics-API mit JSON-Property-
> Filterung und Aggregationsabfragen

Demonstriert eine Event-Tracking-API, die Analytics-Events mit beliebigen JSON-Properties
speichert und Aggregations-Endpunkte für Pro-Tag-Anzahlen, Pro-Typ-Aufschlüsselungen
und Unique-User-Metriken bereitstellt. Schlüsselmuster: `json_extract()`-Property-Filterung, `strftime()`-
Datumsgruppierung, statische Routen vor parametrisierten Routen und String-typisierte Benutzer-IDs.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|---------------------------|-----------------------------------------------------|
| `POST` | `/events`                 | Ein Event aufzeichnen |
| `GET`  | `/events`                 | Events auflisten (paginiert) |
| `GET`  | `/events/by-property`     | Nach JSON-Property-Schlüssel/Wert filtern |
| `GET`  | `/events/{id}`            | Ein einzelnes Event abrufen |
| `GET`  | `/stats/per-day`          | Event-Anzahl pro Kalendertag (`?from=&to=`) |
| `GET`  | `/stats/per-type`         | Event-Anzahl pro Event-Typ (`?from=&to=`) |
| `GET`  | `/stats/unique-users`     | Unique-User-Anzahl pro Tag (`?from=&to=`) |

---

## Events aufzeichnen

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // erforderlich, nicht-leerer String
    'user_id'     => 'usr_abc123',          // erforderlich, String (UUID oder opaque ID)
    'session_id'  => 'sess_xyz789',         // optional
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // optionales Objekt
    'occurred_at' => '2026-05-27T09:00:00Z', // optional, ISO 8601 (Standard: Serverzeit)
];
```

`properties` wird als JSON-String gespeichert. Bei der Ausgabe wird es zurück zu einem Objekt dekodiert:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

Wenn `occurred_at` weggelassen wird, füllt der Server es mit der aktuellen UTC-Zeit:

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## Routen-Reihenfolge: statisch vor parametrisiert

Der Router gleicht Routen in Registrierungsreihenfolge ab. Ein statischer Pfad wie `/events/by-property`
muss **vor** dem parametrisierten `/events/{id}` registriert werden, sonst würde das Segment
`by-property` als `{id}` erfasst:

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ Statische Route zuerst — oder "by-property" wird von {id} verschluckt
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**Regel**: immer konkrete Pfadsegmente vor Wildcard-Segmenten auf derselben Tiefe registrieren.

---

## JSON-Property-Filterung mit `json_extract()`

SQLite (≥ 3.38) und MySQL unterstützen `json_extract()` zum Abfragen in gespeicherten JSON-Spalten.
Der Schlüssel wird als parametrisierter JSONPath-Ausdruck übergeben:

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

Das JSONPath-Präfix `$.` wird in PHP angehängt, also wird `key = "path"` zu
`json_extract(properties, '$.path')`. Da beide Argumente parametrisiert sind,
besteht kein SQL-Injection-Risiko, auch wenn `$propertyKey` Sonderzeichen enthält.

> **Tiefenbegrenzung**: `$.path` greift auf die oberste Ebene zu. Für verschachtelte Zugriffe
> (`$.browser.name`) übergibt der Aufrufer `browser.name` als Schlüssel. Tiefe Pfade können
> überraschend sein — unterstützte Schlüsselformen in der OpenAPI-Spezifikation dokumentieren.

---

## Datenaggregation mit `strftime()`

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` kürzt einen ISO 8601-Datumstring auf seine Datumskomponente.
Dies funktioniert in SQLite, wenn `occurred_at` als UTC gespeichert ist (z. B. `2026-05-27T09:00:00Z`).
Zeiten mit Nicht-UTC-Offsets werden nach ihrem rohen String gruppiert, nicht in Ortszeit umgerechnet —
zur Schreibzeit auf UTC normalisieren, wenn Tagesgrenzen-Semantik wichtig ist.

---

## Unique User pro Tag zählen

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` gibt die Anzahl der verschiedenen `user_id`-Werte zurück, die in
jedem Bucket erscheinen. Dies ist eine Annäherung der täglich aktiven Benutzer (DAU), wenn `user_id`
ein stabiler externer Bezeichner ist (UUID, gehashte Geräte-ID usw.).

---

## String-typisierte user_id

`user_id` wird als `TEXT NOT NULL` gespeichert, nicht als Integer-Fremdschlüssel. Dieses Design
unterstützt:

- UUID (`usr_01HQ...`)
- Opaque-String-Bezeichner von einem Identity Provider
- Anonyme Session-Tokens vor der Kontoerstellung

Da das Feld freier Text ist, koppelt die Analytics-Schicht nicht an das Benutzer-Datenmodell.
Es gibt keinen `REFERENCES users(id)`-Fremdschlüssel — Events können vor oder nach der Erstellung
eines Benutzerkontos aufgezeichnet werden.

---

## Standard-Datumsbereich-Fallback

Aggregat-Endpunkte akzeptieren `?from=` und `?to=` als Query-Parameter. Wenn weggelassen, decken Standardwerte
einen sehr weiten Bereich ab:

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

Dies ist für Demo-Zwecke praktisch, könnte aber bei einem großen Produktionsdatensatz teuer werden.
In der Produktion explizite Datumsbereiche erfordern und die maximale Spanne begrenzen.

---

## Schema und Indizes

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

Drei Indizes decken die drei Haupt-Abfragemuster ab:
- `idx_events_occurred` — Datumsbereich-Aggregationen (`WHERE occurred_at >= ? AND < ?`)
- `idx_events_type` — Typ-Filter (`WHERE event_type = ?`)
- `idx_events_user` — Benutzer-Verlaufsuche (`WHERE user_id = ?`)

`json_extract()`-Abfragen auf `properties` werden in SQLite ohne generierte Spalte nicht durch Indizes unterstützt.
Für hochvolumige Property-Filterung eine generierte Spalte hinzufügen:

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## Properties-Kodierung in PHP

Das Feld `properties` akzeptiert beliebige JSON-Objekte vom Aufrufer und speichert sie als String:

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` lehnt JSON-Skalare und Arrays ab (die zu einem PHP-Array
dekodiert würden, aber kein Objekt sind). `JSON_THROW_ON_ERROR` stellt sicher, dass Enkodierungsfehler
als Exceptions auftauchen statt als stilles `false`.

Bei der Serialisierung werden Properties zurück zu einem PHP-Array dekodiert und als verschachteltes
Objekt in die Antwort eingebettet:

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## Verwandte Anleitungen

- [`admin-report-aggregation.md`](admin-report-aggregation.md) — SQL-Aggregationsmuster für Admin-Berichte
- [`shift-management.md`](shift-management.md) — Datumsbereichsbegrenzung, Aggregationsabfragen
- [`pagination.md`](pagination.md) — `PaginationQueryParser` und `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) — ISO 8601-Round-Trip-Validierung für `occurred_at`
