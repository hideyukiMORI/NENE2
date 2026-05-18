# Rate-Limiting hinzufügen

Diese Anleitung zeigt, wie Sie Ihre NENE2-Anwendung mit Request-Rate-Limiting schützen können,
indem Sie `ThrottleMiddleware` und `RateLimitStorageInterface` verwenden.

**Voraussetzung**: Sie haben eine funktionierende NENE2-Anwendung. Falls nicht, beginnen Sie mit dem [Tutorial](../tutorial/first-api.md).

---

## Schnellstart

Fügen Sie `ThrottleMiddleware` zu `RuntimeApplicationFactory` hinzu. Der eingebaute `InMemoryRateLimitStorage`
ist für lokale Entwicklung und Einzelprozess-Deployments geeignet.

```php
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17    = new Psr17Factory();
$problems = new ProblemDetailsResponseFactory($psr17, $psr17);
$storage  = new InMemoryRateLimitStorage();

$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          60,   // erlaubte Anfragen pro Zeitfenster
    windowSeconds:  60,   // Länge des Zeitfensters in Sekunden
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` befindet sich an Position 8 im Middleware-Stack — nach der Authentifizierung,
sodass Sie bei Bedarf Limits pro authentifiziertem Benutzer festlegen können (siehe Benutzerdefinierter Schlüssel-Extraktor unten).

---

## Funktionsweise

Für jede Anfrage führt die Middleware folgende Schritte aus:

1. Berechnet einen Schlüssel für den Client (Standard: `REMOTE_ADDR`).
2. Erhöht den Zähler im Speicher-Backend.
3. Wenn der Zähler **gleich oder unter** dem Limit liegt — lässt die Anfrage durch und fügt Rate-Limit-Header hinzu.
4. Wenn der Zähler **das Limit überschreitet** — gibt `429 Too Many Requests` mit Problem Details zurück.

### Antwort-Header

Jede Antwort (einschließlich 429) enthält diese Header:

| Header | Wert |
|---|---|
| `X-RateLimit-Limit` | Konfiguriertes Limit pro Zeitfenster |
| `X-RateLimit-Remaining` | Verbleibende Anfragen im aktuellen Zeitfenster |
| `X-RateLimit-Reset` | Unix-Zeitstempel, wann das Zeitfenster zurückgesetzt wird |
| `Retry-After` | Sekunden bis zur Zurücksetzung des Zeitfensters (nur bei 429) |

### 429-Antwort-Body

```json
{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit exceeded. Try again in 42 seconds.",
  "instance": "/examples/notes"
}
```

---

## Benutzerdefinierter Schlüssel-Extraktor

Standardmäßig ist der Schlüssel die Client-IP-Adresse (`REMOTE_ADDR`). Übergeben Sie eine `Closure`,
um Limits pro authentifiziertem Benutzer, API-Schlüssel oder einer anderen Dimension festzulegen.

```php
$throttle = new ThrottleMiddleware(
    problemDetails: $problems,
    storage:        $storage,
    limit:          1000,
    windowSeconds:  3600,
    keyExtractor:   static function (ServerRequestInterface $request): string {
        return $request->getAttribute('user_id', 'anonymous');
    },
);
```

---

## Speicher-Backend austauschen

`InMemoryRateLimitStorage` speichert Zähler im PHP-Prozessspeicher. Diese werden bei jedem Request
in FPM-Deployments zurückgesetzt und werden **nicht zwischen Prozessen geteilt**. Für die Produktion
benötigen Sie einen gemeinsam genutzten Speicher wie Redis.

Implementieren Sie `RateLimitStorageInterface`:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    /** @return array{count: int, reset_at: int} */
    public function hit(string $key, int $windowSeconds): array
    {
        $redisKey = "rate:{$key}";
        $count    = (int) $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }
        $ttl     = max(0, (int) $this->redis->ttl($redisKey));
        $resetAt = time() + $ttl;
        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

---

## Designentscheidungen

Siehe [ADR 0010](/adr/0010-rate-limiting) für die Begründung hinter:
- Wahl des Fixed-Window-Algorithmus
- IP-basierter Standardschlüssel
- Header-Konventionen (`X-RateLimit-*`, `Retry-After`)
- Abstraktionsgrenze `RateLimitStorageInterface`

---

## Nächster Schritt

Siehe [Problem Details-Typen](../reference/problem-details-types.md) für die vollständige `429`-Fehlerstruktur,
oder [Health Check hinzufügen](./add-health-check.md) für die ergänzende Observability-Funktion.
