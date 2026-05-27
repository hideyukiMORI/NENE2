# Anleitung: Kontingentverwaltungs-API

> **FT-Referenz**: FT236 (`NENE2-FT/quotalog`) — Kontingentverwaltungs-API
> **ATK**: FT236 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Kontingentverwaltungs-API, bei der jedes Benutzer/Ressource-Paar eine konfigurierbare
Richtlinie (stündlich oder täglich) hat, der Verbrauch in einer separaten Tabelle nach Fensterbeginn
verfolgt wird und ein `consume`-Endpunkt das Limit mit `429 Too Many Requests` durchsetzt, wenn es
überschritten wird. `check` (nur lesen) und `consume` (mutierend) sind getrennte Operationen.

---

## Routen

| Methode | Pfad                                     | Beschreibung                                       |
|---------|------------------------------------------|----------------------------------------------------|
| `PUT`   | `/quotas/{userId}/{resource}`            | Kontingent-Richtlinie erstellen oder aktualisieren |
| `GET`   | `/quotas/{userId}`                       | Alle Kontingent-Richtlinien für einen Benutzer auflisten |
| `GET`   | `/quotas/{userId}/{resource}`            | Aktuellen Kontingentstatus prüfen (nur lesen)      |
| `POST`  | `/quotas/{userId}/{resource}/consume`    | Eine Einheit verbrauchen (gibt 429 zurück, wenn überschritten) |
| `POST`  | `/quotas/{userId}/{resource}/reset`      | Verbrauch für das aktuelle Fenster auf null zurücksetzen |

---

## QuotaWindow: Fensterbeginn berechnen

`QuotaWindow` ist ein backed enum mit einer `windowStart()`-Methode, die den aktuellen
Zeitstempel auf die Fenstergrenze abrundet:

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` rundet auf die aktuelle Stunde; `setTime(0, 0, 0)` rundet auf Mitternacht
UTC. Das Ergebnis wird als `window_start`-Schlüssel in der Verbrauchstabelle gespeichert — alle Anfragen
innerhalb desselben Fensters haben denselben `window_start`-Wert.

---

## Zwei-Tabellen-Design: Richtlinien und Verbrauch

```sql
-- Kontingent-Richtlinie: maximal erlaubte Anfragen pro Fenster
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- Verbrauchsverfolgung: tatsächliche Anzahl pro Fenster
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

Die Trennung von Richtlinien und Verbrauch bedeutet:
- Richtlinien bleiben über Fenster hinweg bestehen — sie müssen nicht jede Periode neu erstellt werden.
- Verbrauchszeilen werden automatisch nach `window_start` partitioniert. Alte Fenster akkumulieren
  sich in der Tabelle; ein Hintergrundjob kann sie bereinigen.
- `UNIQUE(user_id, resource)` für Richtlinien verhindert doppelte Konfigurationen.
- `UNIQUE(user_id, resource, window_start)` für den Verbrauch stellt einen Zähler pro Fenster sicher.

---

## check vs. consume

`check` ist nur lesend — es berechnet den verbleibenden Verbrauch ohne Mutation:

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` prüft zuerst das Limit und erhöht nur, wenn erlaubt:

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // Kontingent überschritten — Status mit allowed=false zurückgeben, NICHT erhöhen
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

Der Controller ordnet `allowed=false` auf `429 Too Many Requests` zu:

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` ist semantisch korrekt für Kontingenterschöpfung. In der Produktion einen `Retry-After`-Header
hinzufügen, der auf die Fenster-Reset-Zeit verweist.

---

## Verbrauchserhöhung: SELECT-then-INSERT/UPDATE

Die Verbrauchserhöhung ist ein anwendungsseitiges Upsert:

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` ist eine atomare DB-seitige Erhöhung — kein Read-Modify-Write im
Anwendungscode. Die `UNIQUE`-Einschränkung auf `(user_id, resource, window_start)`
verhindert eine Race Condition zwischen zwei gleichzeitigen Erstverwendungs-Inserts.

---

## Richtlinien-Upsert über `PUT`

`PUT /quotas/{userId}/{resource}` ist idempotent — es erstellt oder aktualisiert:

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

Die strikte `is_int()`-Prüfung lehnt JSON-Floats und Strings ab. `limitCount < 1` erfordert
mindestens 1 — Null- und negative Werte werden abgelehnt.

---

## ATK — Cracker-Mindset-Angriffstest (FT236)

### ATK-01 — Keine Authentifizierung

**Angriff**: Eine Kontingent-Richtlinie erstellen oder im Namen eines beliebigen Benutzers verbrauchen, ohne Anmeldedaten.

```bash
curl -s -X PUT http://localhost:8080/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**Beobachtet**: `200 OK` — kein Token erforderlich. Jeder kann das Kontingent eines beliebigen Benutzers setzen oder erschöpfen.

**Urteil**: **EXPONIERT** (absichtlich für FT236-Demo). Authentifizierung hinzufügen; Richtlinienverwaltung hinter eine Admin-Rolle stellen und consume hinter das Token des Eigentümers.

---

### ATK-02 — SQL-Injection über `{resource}`-Pfadparameter

**Angriff**: SQL-Metazeichen in den Ressourcennamen einbetten.

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**Beobachtet**: Der Ressource-String wird in allen Abfragen direkt als parametrisierter `?`-Wert übergeben — keine String-Interpolation. Das injizierte SQL wird als Literalstring gespeichert/verglichen, nicht ausgeführt.

**Urteil**: **BLOCKIERT** — parametrisierte Abfragen verhindern Injection über Pfadparameter.

---

### ATK-03 — Negativer oder null `limit_count`

**Angriff**: Ein Limit von 0 oder -1 setzen, um den Zugang eines anderen Benutzers zu deaktivieren.

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**Beobachtet**: Die Prüfung `$limitCount < 1` wird ausgelöst → `422 Unprocessable Entity` mit einem
strukturierten Fehler für `limit_count`.

**Urteil**: **BLOCKIERT** — Mindest-`limit_count` von 1 auf Anwendungsebene erzwungen.

---

### ATK-04 — Ungültiger `window`-Wert

**Angriff**: Einen nicht unterstützten Window-String senden.

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**Beobachtet**: `QuotaWindow::tryFrom('weekly')` gibt `null` zurück → `422` mit strukturiertem
Fehler für `window`.

**Urteil**: **BLOCKIERT** — backed enum `tryFrom()` lehnt unbekannte Window-Werte ab.

---

### ATK-05 — Verbrauchen ohne Richtlinie

**Angriff**: `POST .../consume` für einen Benutzer/eine Ressource ohne konfigurierte Richtlinie aufrufen.

```bash
curl -s -X POST http://localhost:8080/quotas/user-ghost/api-calls/consume
```

**Beobachtet**: `findPolicy()` gibt `null` zurück → `404 Not Found` mit einer Problem-Details-Antwort.

**Urteil**: **BLOCKIERT** — keine Richtlinie → kein Verbrauch. Der Aufrufer muss vor dem Verbrauch eine Richtlinie konfigurieren.

---

### ATK-06 — Fließkommazahl als `limit_count`

**Angriff**: Einen Float statt eines Integer senden.

```json
{"window": "daily", "limit_count": 9.9}
```

**Beobachtet**: `is_int(9.9)` = `false` in PHP — der von JSON dekodierte Float-Wert
(`float`-Typ) besteht die Prüfung nicht. `$limitCount` wird auf `-1` gesetzt → die `< 1`-Prüfung
wird ausgelöst → `422`.

**Urteil**: **BLOCKIERT** — strikte `is_int()`-Typprüfung lehnt JSON-Floats ab.

---

### ATK-07 — Extrem großer `limit_count`

**Angriff**: `limit_count` auf `PHP_INT_MAX` oder `9999999999` setzen.

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**Beobachtet**: `is_int()` besteht (PHP repräsentiert dies als `int`); `< 1`-Prüfung besteht.
Der Wert wird gespeichert und in Vergleichen ohne Probleme verwendet. Es existiert keine Obergrenze.

**Urteil**: **EXPONIERT** — kein maximaler `limit_count` wird erzwungen. Ein sehr großes Limit ist
effektiv dasselbe wie „kein Limit". Hinzufügen:
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — Race Condition bei gleichzeitigem Verbrauch am Limit

**Angriff**: Zwei gleichzeitige `POST .../consume`-Anfragen senden, wenn `usage == limit - 1`.

**Beobachtet**: Beide Anfragen lesen `usage = limit - 1`, bevor eine Erhöhung ausgeführt wird. Beide
sehen `usage < limitCount` → beide rufen `incrementUsage()` auf. Beide gelingen — Verbrauch endet bei
`limit + 1`, beide Antworten geben `allowed: true` zurück.

**Urteil**: **EXPONIERT** — das Check-then-increment-Muster ist nicht atomar. Mit einer
Transaktion beheben:
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- Prüfen ob < limit
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
Oder `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage` auf PostgreSQL verwenden.

---

### ATK-09 — Unbekannter oder beliebiger `{resource}`-Name

**Angriff**: Einen Ressourcennamen verwenden, der nie beabsichtigt war.

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**Beobachtet**: Path-Traversal (`../`) wird vor dem Routing URL-dekodiert; der Router sieht sie
als Mehrsegment-Pfade und trifft die `{resource}`-Route nicht. Sonderzeichen werden als Literalstrings
über parametrisierte Abfragen gespeichert (siehe ATK-02).

**Urteil**: **BLOCKIERT** — Router lehnt Path-Traversal ab, SQL ist sicher.
Eine Ressourcennamen-Allowlist oder Formatprüfung hinzufügen, wenn Ressourcennamen auf bekannte Werte
beschränkt sein sollen.

---

### ATK-10 — Kontingent eines anderen Benutzers zurücksetzen

**Angriff**: Den Kontingentzähler eines anderen Benutzers zurücksetzen, um seine Drosselung zu umgehen.

```bash
curl -s -X POST http://localhost:8080/quotas/target-user/api-calls/reset
```

**Beobachtet**: `200 OK` — keine Eigentümerprüfung. Jeder Aufrufer kann den Kontingentverbrauch eines beliebigen Benutzers zurücksetzen und so seinen Zugang sofort wiederherstellen.

**Urteil**: **EXPONIERT** — gleiche Ursache wie ATK-01. `reset` hinter einer Admin-Rolle absichern.

---

### ATK-11 — Unbegrenzte Länge von `{userId}` und `{resource}`

**Angriff**: Extrem lange Pfadsegmentwerte senden.

```
PUT /quotas/<10000 Zeichen>/<5000 Zeichen>
```

**Beobachtet**: Lange Strings werden ohne Limit in `TEXT`-Spalten akzeptiert und gespeichert.
Die Index-Performance bei sehr langen Schlüsseln verschlechtert sich.

**Urteil**: **EXPONIERT** — Längenprüfung hinzufügen:
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — `window_start`-Manipulation durch Uhren-Drift

**Angriff**: Wenn der Aufrufer `$now` beeinflussen kann, kann er den Fensterbeginn verschieben, um
ein Fenster künstlich zu verlängern oder neu zu starten.

**Beobachtet**: `$now` wird innerhalb des Controllers über `new \DateTimeImmutable()` berechnet —
es wird nicht vom Benutzer geliefert. Der Aufrufer kann die Fensterberechnung nicht beeinflussen.

**Urteil**: **BLOCKIERT** — die Serveruhr ist die einzige Zeitquelle. Für verteilte
Systeme mit mehreren Knoten sicherstellen, dass alle Knoten UTC verwenden und NTP-synchronisiert sind.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| ATK-01 | Keine Authentifizierung | EXPONIERT |
| ATK-02 | SQL-Injection über Ressource-Pfadparameter | BLOCKIERT |
| ATK-03 | Negativer/null limit_count | BLOCKIERT |
| ATK-04 | Ungültiger window-Wert | BLOCKIERT |
| ATK-05 | Verbrauchen ohne Richtlinie | BLOCKIERT |
| ATK-06 | Fließkommazahl als limit_count | BLOCKIERT |
| ATK-07 | Extrem großer limit_count | EXPONIERT |
| ATK-08 | Gleichzeitige Verbrauchs-Race Condition | EXPONIERT |
| ATK-09 | Beliebiger Ressourcenname | BLOCKIERT |
| ATK-10 | Kontingent eines anderen Benutzers zurücksetzen | EXPONIERT |
| ATK-11 | Unbegrenzte userId/resource-Länge | EXPONIERT |
| ATK-12 | window_start-Manipulation | BLOCKIERT |

**Echte Schwachstellen, die vor dem Produktivbetrieb behoben werden müssen**:
1. **ATK-01 / ATK-10** — Authentifizierung und Autorisierung hinzufügen
2. **ATK-08** — Verbrauch in eine Transaktion einschließen (atomares Check-then-increment)
3. **ATK-07** — Obergrenze für `limit_count` hinzufügen
4. **ATK-11** — Längenlimits für Pfadparameter hinzufügen

---

## Verwandte Anleitungen

- [`rate-limiting.md`](rate-limiting.md) — Ratenbegrenzung auf Middleware-Ebene
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — Sliding-Window-Zähler
- [`api-usage-metering.md`](api-usage-metering.md) — Verbrauchsverfolgung pro API-Key
- [`credit-ledger.md`](credit-ledger.md) — Guthaben/Abbuchungs-Modell für kontingentähnliche Systeme
