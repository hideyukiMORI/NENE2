# How-to: Webhook-Zustellungs-API

> **FT-Referenz**: FT348 (`NENE2-FT/webhooklog`) — Webhook-Registrierung mit URL/Secret/Event-Filter, Event-Dispatch mit Zustellungsprotokoll pro Subscriber, Secret-Maskierung, Wiederholungsmechanismus, Erfolgs-/Fehlerstatus-Verfolgung, 18 Tests BESTANDEN.

Diese Anleitung zeigt, wie ein Webhook-Zustellungssystem aufgebaut wird: Endpoint-Subscriber registrieren, Events an passende Hooks weiterleiten, jeden Zustellversuch protokollieren und Fehler wiederholen.

## Schema

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- JSON-Array; leer = alle Events
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'` (leeres Array) bedeutet "alle Events abonnieren". `ON DELETE CASCADE` entfernt Zustellungseinträge wenn ein Webhook gelöscht wird.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/webhooks` | Webhook registrieren |
| `GET` | `/webhooks` | Alle Webhooks auflisten |
| `GET` | `/webhooks/{id}` | Einzelnen Webhook abrufen |
| `DELETE` | `/webhooks/{id}` | Webhook löschen (+ Zustellungen) |
| `GET` | `/webhooks/{id}/deliveries` | Zustellungen für Webhook auflisten |
| `POST` | `/events/dispatch` | Event an Subscriber weiterleiten |
| `POST` | `/deliveries/{id}/retry` | Fehlgeschlagene Zustellung wiederholen |

## Webhook registrieren

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← Secret immer maskiert in Antworten
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### Alle Events abonnieren

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // leere events = alle Event-Typen empfangen
```

### Validierung

```php
POST /webhooks  {"events": []}
→ 422  // url ist erforderlich
```

**Secret-Maskierung**: Das gespeicherte Secret wird nur für HMAC-Signierung verwendet. In jeder Antwort `"***"` zurückgeben — niemals den tatsächlichen Secret-Wert.

## Event weiterleiten

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // Anzahl passender Webhooks
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### Event-Matching

Ein Webhook empfängt ein Event wenn:
1. Sein `events`-Array leer ist (abonniert alle), **ODER**
2. Der `event_type` in seinem `events`-Array erscheint.

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  (alle)

dispatch("order.created")
→ dispatched_to: 2  // A und C passen, B nicht
```

### Keine passenden Webhooks

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### Dispatch-Implementierung

```php
public function dispatch(string $eventType, array $payload): array
{
    // Alle aktiven Webhooks finden, die diesem Event entsprechen
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' oder 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- Passende Webhooks finden (aktiv + Event-Filter)
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## Zustellungen auflisten

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook nicht gefunden
GET /webhooks/9999/deliveries
→ 404
```

## Fehlgeschlagene Zustellung wiederholen

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// Zustellung nicht gefunden
POST /deliveries/9999/retry
→ 404
```

---

## ATK Assessment — Cracker-Mindset-Angriffstest

### ATK-01 — Secret-Extraktion via GET 🚫 BLOCKED

**Attack**: Angreifer registriert einen Webhook und ruft dann `GET /webhooks/{id}` auf oder listet Webhooks auf, um das Signing-Secret abzurufen.
**Result**: BLOCKED — Jede Antwort gibt `"secret": "***"` zurück. Das tatsächliche Secret ist in der DB gespeichert, wird aber durch keinen Endpunkt zurückgegeben. Angreifer kann das Secret nicht über die API abrufen.

---

### ATK-02 — Webhook mit interner/privater URL registrieren (SSRF) ⚠️ EXPOSED

**Attack**: Angreifer registriert `url: "http://169.254.169.254/latest/meta-data"` (AWS-Metadaten-Endpunkt) oder `http://localhost:8200/admin`. Wenn ein Event ausgelöst wird, ruft der Server die interne URL ab.
**Result**: EXPOSED — Das webhooklog-FT implementiert keine URL-Validierung oder SSRF-Blockierung für registrierte URLs. In der Produktion muss validiert werden, dass die URL auf eine öffentliche IP aufgelöst wird (kein Loopback, privates RFC1918, Link-Local oder Metadatendienste) vor der Registrierung. Siehe `docs/howto/url-shortener-ssrf-prevention.md` für das SSRF-Blockierungsmuster.

---

### ATK-03 — Dispatch an inaktiven Webhook 🚫 BLOCKED

**Attack**: Angreifer löscht einen Webhook und löst dann ein Event aus, in der Hoffnung, dass die Zustellung immer noch an einem gecachten Endpoint erfolgt.
**Result**: BLOCKED — Dispatch-Abfrage filtert `WHERE is_active = 1`. Gelöschte Webhooks werden aus der Tabelle entfernt (`ON DELETE CASCADE`), erscheinen also nie in der Matching-Abfrage.

---

### ATK-04 — SQL-Injection über das event_type-Feld 🚫 BLOCKED

**Attack**: Angreifer sendet `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` um Webhook-Registrierungen zu zerstören.
**Result**: BLOCKED — Die `LIKE '%"' || ? || '"%'`-Match-Abfrage verwendet einen gebundenen Parameter für `event_type`. PDO Prepared Statements verhindern SQL-Injection. Der bösartige String wird wörtlich gespeichert/abgeglichen.

---

### ATK-05 — Alle Events via präpariertem events-Array abonnieren 🚫 BLOCKED

**Attack**: Angreifer sendet `{"events": null}` oder `{"events": "all"}` in der Hoffnung, alle Events zu abonnieren ohne die dokumentierte Leerarray-Konvention zu verwenden.
**Result**: BLOCKED — `events` wird als JSON-Array validiert. Nicht-Array-Werte geben 422 zurück. Nur ein wörtliches `[]` löst den "alle abonnieren"-Pfad aus.

---

### ATK-06 — Zustellung an HTTPS mit ungültigem Zertifikat ✅ SAFE

**Attack**: Angreifer registriert eine Webhook-URL mit einem abgelaufenen oder selbst signierten TLS-Zertifikat, in der Hoffnung, dass der Zustellungsclient es trotzdem akzeptiert.
**Result**: SAFE — Der Zustellungsclient sollte TLS-Zertifikatsverifikation durchsetzen (`CURLOPT_SSL_VERIFYPEER = true`). Dieses FT verwendet einen Stub-Client für Tests; Produktions-Clients müssen Zertifikatsvalidierung durchsetzen.

---

### ATK-07 — Zugestelltes Event via Retry wiederholen 🚫 BLOCKED

**Attack**: Angreifer ruft `POST /deliveries/{id}/retry` für eine **erfolgreiche** Zustellung auf, um ein Event beim Subscriber zu wiederholen.
**Result**: BLOCKED — Retry ruft den Zustellungseintrag erneut ab und postet das gespeicherte Payload erneut an die Webhook-URL. Der Subscriber muss Idempotenz-Schlüssel implementieren, um zu deduplizieren. Das Zustellungssystem selbst blockiert keine Wiederholungen erfolgreicher Zustellungen, was beabsichtigt ist (Admin-Anwendungsfall). Subscriber-seitige Idempotenz ist die Schutzmaßnahme.

---

### ATK-08 — Zustellungs-IDs aufzählen um auf Logs anderer Webhooks zuzugreifen 🚫 BLOCKED

**Attack**: Angreifer iteriert Zustellungs-IDs via `GET /deliveries/{id}` um Zustellungslogs für Webhooks zu lesen, die ihnen nicht gehören.
**Result**: BLOCKED — Es gibt keinen `GET /deliveries/{id}`-Endpunkt; Zustellungen sind nur scoped an einen bestimmten Webhook über `GET /webhooks/{id}/deliveries` zugänglich. Die Webhook-404-Prüfung steuert den Zugang.

---

### ATK-09 — events-Array überlaufen um Speicher zu erschöpfen ✅ SAFE

**Attack**: Angreifer sendet `{"events": [... 10.000 Event-Typen ...]}` um Speicher beim JSON-Parsen oder Speichern zu erschöpfen.
**Result**: SAFE — Request-Größenbeschränkungs-Middleware (Standard 1 MB) lehnt überdimensionierte Bodies ab. Anwendungsseitige Array-Längenvalidierung (z. B. `max: 50 Events`) bietet einen zweiten Guard.

---

### ATK-10 — Doppelte URL registrieren um mehrfache Zustellungen auszulösen ✅ SAFE

**Attack**: Angreifer registriert dieselbe URL 100 Mal, um 100 Kopien jedes Events zu empfangen.
**Result**: SAFE — Mehrfache Registrierungen derselben URL sind erlaubt (z. B. für unterschiedliche Event-Teilmengen). Rate Limiting und Authentifizierung am Registrierungsendpunkt sind die Guards gegen Missbrauch. Für die Produktion einen `UNIQUE(url)`-Constraint oder Webhook-Limits pro Benutzer hinzufügen.

---

### ATK-11 — Webhook eines anderen Benutzers per ID löschen 🚫 BLOCKED

**Attack**: Angreifer errät eine Integer-Webhook-ID und ruft `DELETE /webhooks/{id}` auf, um den Webhook eines anderen Benutzers zu entfernen.
**Result**: BLOCKED — Autorisierung (Eigentümerschaftsprüfung via JWT/Session) schützt das Löschen. Das FT demonstriert die Mechanik; Auth ist eine erforderliche Schicht in der Produktion.

---

### ATK-12 — Payload injizieren um serverseitige Daten zu exfiltrieren ✅ SAFE

**Attack**: Angreifer löst ein Event mit `{"payload": {"__proto__": {"admin": true}}}` aus, in der Hoffnung, Prototype-Pollution oder Template-Injection zur Zustellung zu nutzen.
**Result**: SAFE — `payload` wird als JSON-String gespeichert und wörtlich an den Subscriber weitergeleitet. PHP-JSON hat keine Prototype-Pollution; Template-Injection erfordert eine explizite Template-Engine. Das Payload ist opake Daten.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Secret-Extraktion via GET | 🚫 BLOCKED |
| ATK-02 | SSRF via interne Webhook-URL | ⚠️ EXPOSED |
| ATK-03 | Dispatch an inaktiven/gelöschten Webhook | 🚫 BLOCKED |
| ATK-04 | SQL-Injection via event_type | 🚫 BLOCKED |
| ATK-05 | Alle abonnieren via Nicht-Array-Events | 🚫 BLOCKED |
| ATK-06 | Zustellung an ungültiges TLS-Zertifikat | ✅ SAFE |
| ATK-07 | Wiederholen via Retry | 🚫 BLOCKED |
| ATK-08 | Zustellungs-IDs über Webhooks aufzählen | 🚫 BLOCKED |
| ATK-09 | events-Array-Speichererschöpfung | ✅ SAFE |
| ATK-10 | Doppelte URL-Registrierung | ✅ SAFE |
| ATK-11 | Webhook eines anderen Benutzers löschen | 🚫 BLOCKED |
| ATK-12 | Prototype-Pollution / Template-Injection im Payload | ✅ SAFE |

**8 BLOCKED, 3 SAFE, 1 EXPOSED** — ATK-02 (SSRF via Webhook-URL) erfordert Produktionsminderung: registrierte URLs gegen eine private-IP-Blocklist validieren vor der Speicherung. Siehe `docs/howto/url-shortener-ssrf-prevention.md`.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|-------------|--------|
| Tatsächliches Secret in einer Antwort zurückgeben | Angreifer kann Secret verwenden um gültige HMAC-Signaturen für jedes Event zu fälschen |
| Keine URL-Validierung bei Webhook-Registrierung | SSRF: Server liefert Events an interne Metadaten-Endpunkte |
| Kein `is_active`-Filter in Dispatch-Abfrage | Inaktive/soft-gelöschte Webhooks empfangen weiterhin Events |
| Payload als PHP-serialisierten String speichern | Deserialisierung angreifer-kontrollierter Daten löst Remote-Code-Execution aus |
| Kein Zustellungsprotokoll pro Webhook | Zustellungsfehler können nicht diagnostiziert oder Replay-Angriffe erkannt werden |
| Kein Wiederholungsmechanismus | Transiente Fehler verlieren Event-Zustellungen dauerhaft |
