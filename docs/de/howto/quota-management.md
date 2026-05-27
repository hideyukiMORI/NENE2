# How-to: Quota-Management-API

> **FT-Referenz**: FT236 (`NENE2-FT/quotalog`) — Quota-Management-API
> **ATK**: FT236 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Demonstriert eine Quota-Management-API, bei der jedes Benutzer/Ressource-Paar eine konfigurierbare
Ratenpolitik (stündlich oder täglich) hat, die Nutzung in einer separaten Tabelle mit Fensterstartschlüssel
verfolgt wird und ein `consume`-Endpunkt das Limit mit `429 Too Many Requests` bei Überschreitung erzwingt.
`check` (nur-lesen) und `consume` (mutierend) sind separate Operationen.

---

## Routen

| Methode | Pfad | Beschreibung |
|--------|------|-------------|
| `PUT`  | `/quotas/{userId}/{resource}` | Quota-Richtlinie erstellen oder aktualisieren |
| `GET`  | `/quotas/{userId}` | Alle Quota-Richtlinien für einen Benutzer auflisten |
| `GET`  | `/quotas/{userId}/{resource}` | Aktuellen Quota-Status prüfen (nur-lesen) |
| `POST` | `/quotas/{userId}/{resource}/consume` | Eine Einheit verbrauchen (gibt 429 zurück wenn überschritten) |
| `POST` | `/quotas/{userId}/{resource}/reset` | Nutzung im aktuellen Fenster auf null zurücksetzen |

---

## QuotaWindow: Fensterstart berechnen

`QuotaWindow` ist ein backed Enum mit einer `windowStart()`-Methode, die den aktuellen
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

`setTime(H, 0, 0)` rundet auf die aktuelle Stunde ab; `setTime(0, 0, 0)` rundet auf Mitternacht
UTC ab. Das Ergebnis wird als `window_start`-Schlüssel in der Nutzungstabelle gespeichert — alle Anfragen
innerhalb desselben Fensters teilen denselben `window_start`-Wert.

---

## Zwei-Tabellen-Design: Richtlinien und Nutzung

```sql
-- Quota-Richtlinie: maximal erlaubt pro Fenster
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

-- Nutzungsverfolgung: tatsächliche Anzahl pro Fenster
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

Die Trennung von Richtlinien und Nutzung bedeutet:
- Richtlinien bleiben über Fenster hinweg bestehen — keine Neuerstellung pro Zeitraum nötig.
- Nutzungszeilen werden automatisch nach `window_start` partitioniert. Alte Fenster akkumulieren
  sich in der Tabelle; ein Hintergrundjob kann sie bereinigen.
- `UNIQUE(user_id, resource)` bei Richtlinien verhindert doppelte Konfigurationen.
- `UNIQUE(user_id, resource, window_start)` bei Nutzung stellt einen Zähler pro Fenster sicher.

---

## check vs. consume

`check` ist nur-lesen — es berechnet den Restbetrag ohne Mutation:

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

`consume` prüft das Limit zuerst und inkrementiert nur wenn erlaubt:

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // Quota überschritten — Status zurückgeben mit allowed=false, NICHT inkrementieren
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

Der Controller bildet `allowed=false` auf `429 Too Many Requests` ab:

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` ist semantisch korrekt für Quota-Erschöpfung. Im Produktionsbetrieb einen `Retry-After`-Header
hinzufügen, der auf die Fenster-Reset-Zeit zeigt.

---

## ATK — Cracker-Mindset-Angriffstest (FT236)

### ATK-01 — Keine Authentifizierung ⚠️ EXPONIERT

**Angriff**: Quota-Richtlinie erstellen oder im Namen eines beliebigen Benutzers verbrauchen ohne Anmeldedaten.
**Beobachtet**: `200 OK` — kein Token erforderlich. Jeder kann die Quota eines beliebigen Benutzers setzen oder erschöpfen.
**Urteil**: **EXPONIERT** (by design für FT236 Demo). Authentifizierung hinzufügen; Richtlinienverwaltung hinter einer Admin-Rolle absichern, und consume hinter dem Token des besitzenden Benutzers.

---

### ATK-02 — SQL-Injection via `{resource}` Pfadparameter 🚫 BLOCKIERT

**Angriff**: SQL-Metazeichen in den Ressourcennamen einbetten.
**Beobachtet**: Die Ressourcenzeichenkette wird direkt als parametrisierter `?`-Wert in allen Abfragen übergeben — keine Zeichenketteninterpolation. Das injizierte SQL wird als Literalzeichenkette gespeichert/verglichen, nicht ausgeführt.
**Urteil**: **BLOCKIERT** — parametrisierte Abfragen verhindern Injection via Pfadparameter.

---

### ATK-03 — Negatives oder null `limit_count` 🚫 BLOCKIERT

**Angriff**: Limit von 0 oder -1 setzen, um den Zugang eines anderen Benutzers zu deaktivieren.
**Beobachtet**: `$limitCount < 1`-Prüfung greift → `422 Unverarbeitbare Entität` mit strukturiertem Fehler für `limit_count`.
**Urteil**: **BLOCKIERT** — Minimum `limit_count` von 1 auf Anwendungsebene erzwungen.

---

### ATK-04 — Ungültiger `window`-Wert 🚫 BLOCKIERT

**Angriff**: Nicht unterstützte Window-Zeichenkette senden.
**Beobachtet**: `QuotaWindow::tryFrom('weekly')` gibt `null` zurück → `422` mit strukturiertem Fehler für `window`.
**Urteil**: **BLOCKIERT** — backed Enum `tryFrom()` lehnt unbekannte Window-Werte ab.

---

### ATK-05 — Verbrauchen ohne Richtlinie 🚫 BLOCKIERT

**Angriff**: `POST .../consume` für Benutzer/Ressource ohne konfigurierte Richtlinie aufrufen.
**Beobachtet**: `findPolicy()` gibt `null` zurück → `404 Not Found` mit Problem-Details-Antwort.
**Urteil**: **BLOCKIERT** — keine Richtlinie → kein Verbrauch.

---

### ATK-06 — Gleitkomma `limit_count` 🚫 BLOCKIERT

**Angriff**: Gleitkommazahl statt Integer senden.
**Beobachtet**: `is_int(9.9)` = `false` in PHP — der Gleitkommawert (decodiert aus JSON) schlägt die Prüfung fehl.
**Urteil**: **BLOCKIERT** — `is_int()` strenge Typprüfung lehnt JSON-Gleitkommazahlen ab.

---

### ATK-07 — Extrem großes `limit_count` ⚠️ EXPONIERT

**Angriff**: limit_count auf `PHP_INT_MAX` oder `9999999999` setzen.
**Beobachtet**: `is_int()` besteht (PHP repräsentiert dies als `int`); `< 1`-Prüfung besteht. Kein Obergrenze existiert.
**Urteil**: **EXPONIERT** — kein maximales `limit_count` erzwungen. Hinzufügen:
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — Race Condition bei gleichzeitigem Verbrauch am Limit ⚠️ EXPONIERT

**Angriff**: Zwei gleichzeitige `POST .../consume`-Anfragen senden, wenn `usage == limit - 1`.
**Beobachtet**: Beide lesen `usage = limit - 1` bevor einer der Inkrements läuft. Beide sehen `usage < limitCount` → beide rufen `incrementUsage()` auf. Beide erfolgreich — Nutzung endet bei `limit + 1`.
**Urteil**: **EXPONIERT** — das Prüfen-dann-Inkrementieren-Muster ist nicht atomar. Mit einer Transaktion beheben.

---

### ATK-09 — Unbekannter oder beliebiger `{resource}`-Name 🚫 BLOCKIERT

**Angriff**: Ressourcenname verwenden, der nie beabsichtigt war.
**Beobachtet**: Pfad-Traversal (`../`) wird vor dem Routing URL-dekodiert; der Router sieht sie als mehrsegmentige Pfade und stimmt nicht mit der `{resource}`-Route überein.
**Urteil**: **BLOCKIERT** in der Praxis — Router lehnt Pfad-Traversal ab, SQL ist sicher.

---

### ATK-10 — Quota eines anderen Benutzers zurücksetzen ⚠️ EXPONIERT

**Angriff**: Quota-Zähler eines anderen Benutzers zurücksetzen, um deren Drosselung zu umgehen.
**Beobachtet**: `200 OK` — keine Eigentumsüberprüfung. Jeder Aufrufer kann die Quota-Nutzung eines beliebigen Benutzers zurücksetzen.
**Urteil**: **EXPONIERT** — gleiche Wurzel wie ATK-01. `reset` hinter einer Admin-Rolle absichern.

---

### ATK-11 — Unbegrenzte Länge von `{userId}` und `{resource}` ⚠️ EXPONIERT

**Angriff**: Extrem lange Pfadsegmentwerte senden.
**Beobachtet**: Lange Zeichenketten werden ohne Limit in `TEXT`-Spalten gespeichert.
**Urteil**: **EXPONIERT** — Längenguard hinzufügen.

---

### ATK-12 — `window_start`-Manipulation via Uhrendrift 🚫 BLOCKIERT

**Angriff**: Wenn der Aufrufer `$now` beeinflussen kann, kann er den Fensterstart verschieben.
**Beobachtet**: `$now` wird innerhalb des Controllers über `new \DateTimeImmutable()` berechnet — nicht vom Benutzer geliefert.
**Urteil**: **BLOCKIERT** — Server-Uhr ist die einzige Zeitquelle.

---

## ATK-Zusammenfassung

| # | Angriffsvektor | Urteil |
|---|----------------|--------|
| ATK-01 | Keine Authentifizierung | EXPONIERT |
| ATK-02 | SQL-Injection via Resource-Pfadparam | BLOCKIERT |
| ATK-03 | Negatives/null limit_count | BLOCKIERT |
| ATK-04 | Ungültiger window-Wert | BLOCKIERT |
| ATK-05 | Verbrauchen ohne Richtlinie | BLOCKIERT |
| ATK-06 | Gleitkomma limit_count | BLOCKIERT |
| ATK-07 | Extrem großes limit_count | EXPONIERT |
| ATK-08 | Gleichzeitige consume Race Condition | EXPONIERT |
| ATK-09 | Beliebiger Ressourcenname | BLOCKIERT |
| ATK-10 | Quota eines anderen Benutzers zurücksetzen | EXPONIERT |
| ATK-11 | Unbegrenzte userId/resource-Länge | EXPONIERT |
| ATK-12 | window_start-Manipulation | BLOCKIERT |

**Kritische Schwachstellen für Produktion beheben**:
1. **ATK-01 / ATK-10** — Authentifizierung und Autorisierung hinzufügen
2. **ATK-08** — consume in eine Transaktion einwickeln (atomares Prüfen-dann-Inkrementieren)
3. **ATK-07** — Obergrenze für `limit_count` hinzufügen
4. **ATK-11** — Längenlimits für Pfadparameterwerte hinzufügen

---

## Verwandte Handbücher

- [`rate-limiting.md`](rate-limiting.md) — Ratenbegrenzung auf Middleware-Ebene
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — Gleitendes Fenster Zähler
- [`api-usage-metering.md`](api-usage-metering.md) — Nutzungsverfolgung pro API-Schlüssel
- [`credit-ledger.md`](credit-ledger.md) — Guthaben/Lastschrift-Modell für quota-ähnliche Systeme
