# How-to: Fixed-Window Rate Limiter

> **FT-Referenz**: FT251 (`NENE2-FT/ratelimitlog`) — Fixed-Window Rate Limiting mit SQLite-Upsert

Demonstriert einen in SQLite gespeicherten Fixed-Window Rate Limiter. Jedes `(key, window_start)`-Paar akkumuliert einen Request-Zähler. Wenn der Zähler das konfigurierte Limit überschreitet, wird der Request mit `429 Too Many Requests` und einem `Retry-After`-Header abgelehnt.

---

## Routen

| Methode | Pfad      | Beschreibung                                          |
|---------|-----------|-------------------------------------------------------|
| `GET`   | `/ping`   | Rate-limitierter Endpunkt (liest `X-Client-Key`)      |
| `GET`   | `/status` | Read-only-Zähler für einen Key (`?key=`)              |

---

## Schema: Composite Primary Key als Rate-Limit-Counter-Store

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- ISO 8601 Timestamp auf Window-Grenze gekürzt
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` identifiziert eindeutig einen Zähler für jedes `(Client, Window)`-Paar. Der Index macht die Upsert-Suche schnell. Eine separate `api_calls`-Log-Tabelle zeichnet jede erfolgreiche Anfrage für Audit-Zwecke auf.

---

## Upsert-Muster: `INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

Die erste Anfrage für ein `(key, windowStart)`-Paar fügt `count = 1` ein. Nachfolgende Anfragen innerhalb desselben Fensters inkrementieren atomar via `DO UPDATE SET count = count + 1`. Kein `SELECT` vor dem `INSERT` ist nötig — der Upsert ist in SQLite atomar.

Nach dem Upsert wird der Zähler gelesen, um festzustellen, ob das Limit überschritten wurde:

```php
$row   = $this->executor->fetchOne(
    'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
    [$key, $windowStart],
);
$count = (int) ($row['count'] ?? 0);

if ($count > $this->limit) {
    $retryAfter = (int) (strtotime($windowEnd) - strtotime($now));
    throw new RateLimitExceededException($key, $this->limit, $this->windowSeconds, max(0, $retryAfter));
}
```

Die Prüfung erfolgt **nach** dem Inkrement. Das bedeutet, die (limit+1)-te Anfrage wird gezählt, bevor sie abgelehnt wird — der Zähler erreicht `limit + 1` für Über-Limit-Anfragen. Dies ist beabsichtigt: der Zähler spiegelt die Gesamtanzahl der Versuche wider, nicht nur die erlaubten.

---

## Window-Trunkierung: feste Grenze

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` ist der Offset innerhalb des aktuellen Fensters. Das Subtrahieren ergibt den Window-Start-Timestamp. Für ein 60-Sekunden-Fenster bei `2026-01-01T00:00:45Z`:

```
ts     = 1751328045  (Unix-Timestamp)
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

Alle Anfragen von `:00` bis `:59` teilen denselben `window_start = 2026-01-01T00:00:00Z`. Bei `:60` beginnt ein neues Fenster und der Zähler setzt sich zurück.

**Fixed vs. Sliding Window — Abwägung**:

| Eigenschaft | Fixed Window | Sliding Window |
|---|---|---|
| Implementierung | Einzelner Upsert pro Anfrage | Mehrfache Lese-/Schreibvorgänge über Buckets |
| Speicher | 1 Zeile pro (Key, Window) | N Zeilen pro Key (Sub-Buckets) |
| Burst an der Grenze | Ja — 2× Limit an Window-Kante möglich | Nein — begrenzt gleichmäßig über die Zeit |
| Häufige Nutzung | Einfache APIs, interne Tools | Öffentliche APIs, strenge Fairness |

---

## `429 Too Many Requests` mit `Retry-After`

```php
final class RateLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $key,
        public readonly int    $limit,
        public readonly int    $windowSeconds,
        public readonly int    $retryAfter,
    ) {
        parent::__construct("Rate limit of {$limit} requests per {$windowSeconds}s exceeded for key '{$key}'.");
    }
}
```

Die Exception trägt `retryAfter` (Sekunden bis das aktuelle Fenster endet). Der Handler mappt dies auf eine `429` Problem Details-Antwort mit einem `Retry-After`-Header:

```php
public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
{
    assert($exception instanceof RateLimitExceededException);

    $response = $this->probs->create(
        request: $request,
        type: 'rate-limit-exceeded',
        title: 'Too Many Requests',
        status: 429,
        detail: $exception->getMessage(),
    );

    return $response->withHeader('Retry-After', (string) $exception->retryAfter);
}
```

`Retry-After` ist die Anzahl der Sekunden, die der Client warten soll, bevor er es erneut versucht. Es wird als `windowEnd - now` berechnet, auf `>= 0` begrenzt.

---

## Per-Client-Key via `X-Client-Key`-Header

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

Jeder Client wird durch seinen `X-Client-Key`-Header identifiziert. Fehlender Header fällt auf `'127.0.0.1'` zurück — alle nicht-authentifizierten Clients teilen sich einen Zähler. In der Produktion:

- Eine verifizierte User-ID oder einen API-Key aus einer authentifizierten Session verwenden — keinen Header, den der Client fälschen kann.
- `$_SERVER['REMOTE_ADDR']` (nach Proxy-Stripping) für IP-basiertes Limiting verwenden.
- `X-Forwarded-For` niemals direkt verwenden — ein Client kann es fälschen, um Limits zu umgehen.

---

## Read-only-Status-Endpunkt

```php
public function currentCount(string $key, string $now): int
{
    $windowStart = $this->truncateToWindow($now);
    $row = $this->executor->fetchOne(
        'SELECT count FROM rate_limit_windows WHERE key = ? AND window_start = ?',
        [$key, $windowStart],
    );

    return (int) ($row['count'] ?? 0);
}
```

`GET /status?key=xxx` gibt den aktuellen Zähler zurück ohne ihn zu inkrementieren. Wird für Monitoring-Dashboards oder Client-seitige Backoff-Logik verwendet.

---

## Window-Ablauf-Bereinigung

```php
public function pruneExpired(string $now): int
{
    $cutoff = $this->subtractSeconds($now, $this->windowSeconds * 2);

    return $this->executor->execute(
        'DELETE FROM rate_limit_windows WHERE window_start < ?',
        [$cutoff],
    );
}
```

Alte Fenster häufen sich mit der Zeit an. `pruneExpired()` löscht Zeilen, die älter als zwei Window-Dauern sind (aktuelles Fenster + vorheriges Fenster werden behalten; ältere werden entfernt).

`pruneExpired()` aus einem Hintergrund-Task oder nach jeder Anfrage ausführen (mit Sampling — z. B. `rand(0, 99) === 0` für ~1% der Anfragen):

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## Konfigurations-Injection

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` und `windowSeconds` werden bei der Konstruktion injiziert. Verschiedene Endpunkte können verschiedene Limiter-Instanzen mit unterschiedlichen Konfigurationen verwenden:

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## Verwandte Anleitungen

- [`rate-limiting.md`](rate-limiting.md) — `ThrottleMiddleware` für routen-spezifisches Rate Limiting
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — Sliding Window mit Sub-Buckets (ratelog FT200)
- [`add-rate-limiting.md`](add-rate-limiting.md) — Rate Limiting zu einer bestehenden Route hinzufügen
- [`quota-management.md`](quota-management.md) — längerfristige Quotas (täglich, monatlich)
- [`api-usage-metering.md`](api-usage-metering.md) — benutzerbezogenes Usage-Tracking mit Quota-Prüfung
