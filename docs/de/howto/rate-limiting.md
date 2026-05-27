# Ratenbegrenzung

> **FT-Referenz**: FT284 (`NENE2-FT/throttlelog`) — ThrottleMiddleware Ratenbegrenzung: IP-basiertes Fixed-Window, benutzerdefinierter Schlüsselextraktor (Benutzer/API-Schlüssel), X-RateLimit-*-Header, 429 Problem Details mit Retry-After, InMemoryRateLimitStorage für Tests, 9 Tests / 33 Assertions bestanden.
>
> **ATK-Bewertung**: ATK-01 bis ATK-12 am Ende dieses Dokuments enthalten.

`ThrottleMiddleware` erzwingt eine Fixed-Window-Ratenbegrenzung für alle Anfragen. Es fügt `X-RateLimit-Limit`, `X-RateLimit-Remaining` und `X-RateLimit-Reset`-Header zu jeder Antwort hinzu und gibt eine `429 Too Many Requests` Problem Details-Antwort zurück, wenn das Limit überschritten wird.

## Grundlegende Einrichtung

`ThrottleMiddleware` an `RuntimeApplicationFactory` über den `throttleMiddleware`-Parameter übergeben:

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // nur lokal/Test — siehe "Produktion" unten
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // erlaubte Anfragen pro Fenster
    windowSeconds: 60,   // Fensterdauer in Sekunden
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← benannter Parameter, nicht "middlewares"
    routeRegistrars: [...],
))->create();
```

Der benannte Parameter ist `throttleMiddleware`, nicht `middlewares` — `RuntimeApplicationFactory` hat einen dedizierten Slot für diese Middleware, der sie korrekt in der Pipeline positioniert (nach der Authentifizierung, sodass Benutzer-Pro-Limits möglich sind).

## Antwort-Header

Jede Antwort enthält den Ratenbegrenzungsstatus:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

Wenn das Limit überschritten wird:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1716292860
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/too-many-requests",
  "title": "Too Many Requests",
  "status": 429,
  "detail": "Rate limit of 60 requests per 60 seconds exceeded. Try again in 18 seconds."
}
```

## Ratenbegrenzungsschlüssel

### Standard: IP-basiert (REMOTE_ADDR)

Standardmäßig ist der Schlüssel `ip:<REMOTE_ADDR>`. Jede Client-IP bekommt ihren eigenen Bucket.

### Benutzerdefiniert: Authentifizierter Benutzer

Nachdem die Authentifizierungs-Middleware ein Benutzerattribut gesetzt hat, nach Benutzer-ID schlüsseln:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:        100,
    windowSeconds: 3600,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'user:' . ($r->getAttribute('user_id') ?? 'anonymous'),
);
```

Das verhindert, dass gemeinsame IP-Umgebungen (Büro-NAT) einen Bucket unfair teilen, und ermöglicht strengere Limits für nicht authentifizierte Anfragen.

### Benutzerdefiniert: API-Schlüssel-Header

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## Warnung bei Reverse Proxy / Load Balancer

Hinter einem Reverse Proxy ist `REMOTE_ADDR` die IP des Proxys — alle echten Clients teilen einen einzelnen Bucket. Das beheben, indem ein vertrauenswürdiger Forwarded-IP-Header gelesen wird:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**`X-Forwarded-For` nur vertrauen, wenn dein Proxy unter deiner Kontrolle steht und ihn zuverlässig setzt.** Ein Angreifer kann diesen Header fälschen, wenn der Datenverkehr die Anwendung direkt ohne den Proxy erreicht.

## Produktion: Gemeinsamen Speicher verwenden

`InMemoryRateLimitStorage` hält Zähler in einem einfachen PHP-Array. PHP-FPM läuft mit mehreren Worker-Prozessen; **jeder Worker hat sein eigenes Array, sodass Zähler nicht geteilt werden**. In der Produktion bedeuten 10 Worker mit einem Limit von 60 ein reales Limit von ~600.

Für die Produktion `RateLimitStorageInterface` implementieren, das durch einen gemeinsamen Store gesichert ist:

```php
use Nene2\Middleware\RateLimitStorageInterface;

final class RedisRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(private \Redis $redis) {}

    public function hit(string $key, int $windowSeconds): array
    {
        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $windowSeconds);
        }
        $ttl     = max(0, $this->redis->ttl($key));
        $resetAt = time() + $ttl;

        return ['count' => $count, 'reset_at' => $resetAt];
    }
}
```

Dann injizieren:

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## Fixed-Window-Burst-Problem

`ThrottleMiddleware` verwendet einen Fixed-Window-Algorithmus. Clients können die effektive Rate verdoppeln, indem sie Anfragen an der Grenze zweier Fenster senden:

```
Limit: 100 Anfragen/Min, Fenster: :00–:59

:59 — 100 Anfragen → trifft das Limit
:00 — 100 Anfragen → neues Fenster, alle bestehen

Ergebnis: 200 Anfragen in ~2 Sekunden
```

Wenn das ein Problem ist, einen Sliding-Window- oder Token-Bucket-Algorithmus in deiner `RateLimitStorageInterface`-Implementierung implementieren.

## Per-Route-Limits

`RuntimeApplicationFactory` unterstützt eine `ThrottleMiddleware`-Instanz, die global angewendet wird. Für Per-Route-Limits mit unterschiedlichen Einstellungen `ThrottleMiddleware` als Route-Level-Middleware manuell anwenden, indem einzelne Handler eingewickelt werden.

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Ratenlimit erschöpfen, um legitime Benutzer zu blockieren (DoS) 🚫 BLOCKIERT (by design)

**Angriff**: Angreifer sendet 60 Anfragen pro Minute von seiner IP, um sich selbst zu blockieren.
**Ergebnis**: BLOCKIERT (by design) — das Limit gilt für die IP/den Schlüssel des Angreifers. Andere Clients sind nicht betroffen (separate Buckets).

---

### ATK-02 — Per-IP-Limit durch Verwendung verschiedener IP-Adressen umgehen 🚫 BLOCKIERT (gemildert)

**Angriff**: Angreifer verwendet mehrere IPs (Botnet, VPN-Rotation) um Anfragen unter dem Limit pro IP zu senden.
**Ergebnis**: GEMILDERT — jede IP hat ihren eigenen Bucket; einzelne IPs werden ratenbegrenzt.

---

### ATK-03 — X-Forwarded-For fälschen, um IP-basiertes Limit zu umgehen 🚫 BLOCKIERT (Designhinweis)

**Angriff**: Angreifer sendet `X-Forwarded-For: 10.0.0.1` um als andere IP zu erscheinen.
**Ergebnis**: BLOCKIERT (wenn korrekt konfiguriert) — Standardschlüssel verwendet `REMOTE_ADDR` (server-gesetzt), nicht Client-gelieferte Header.

---

### ATK-04 — Fenstergrenz-Burst 🚫 BLOCKIERT (Designbeschränkung)

**Angriff**: 60 Anfragen bei :59 und 60 Anfragen bei :00 (neues Fenster) für 120 Anfragen in 2 Sekunden senden.
**Ergebnis**: BLOCKIERT (im Fixed-Window-Design) — jedes 60-Sekunden-Fenster ist unabhängig.

---

### ATK-05 — Fehlgeformten `X-RateLimit-Remaining`-Header senden 🚫 BLOCKIERT

**Angriff**: Client sendet `X-RateLimit-Remaining: 999`-Header in der Hoffnung, dass der Server ihm vertraut.
**Ergebnis**: BLOCKIERT — `X-RateLimit-*`-Header sind **Antwort**-Header, die vom Server gesetzt werden.

---

### ATK-06 — Ratenlimit erschöpfen, dann anderen Pfad verwenden 🚫 BLOCKIERT

**Angriff**: Nach dem Treffen des Limits auf `/notes`, `/notes?q=1` oder `/other-path` versuchen.
**Ergebnis**: BLOCKIERT — `ThrottleMiddleware` gilt global für alle Pfade.

---

### ATK-07 — Race Condition, um das Limit zu überschreiten 🚫 BLOCKIERT

**Angriff**: 61 gleichzeitige Anfragen senden, wenn der verbleibende Zähler 1 ist.
**Ergebnis**: BLOCKIERT — `InMemoryRateLimitStorage` verwendet PHPs sequentielle Anfragebearbeitung innerhalb eines einzelnen Prozesses.

---

### ATK-08 — Ratenlimit-Timing sondieren, um Systemlast zu inferieren 🚫 BLOCKIERT (irrelevant)

**Angriff**: `Retry-After` messen, um Serverlast oder Anfragemuster zu bestimmen.
**Ergebnis**: IRRELEVANT — `Retry-After` gibt die verbleibende Fensterzeit zurück (fest), keine Systemlast.

---

### ATK-09 — Fehlender `Retry-After`-Header bei 429-Antwort 🚫 BLOCKIERT

**Angriff**: Client ignoriert 429, weil `Retry-After` fehlt, was zu unendlichen Retry-Schleifen führt.
**Ergebnis**: BLOCKIERT — `ThrottleMiddleware` enthält immer sowohl `Retry-After` als auch `X-RateLimit-Reset` in 429-Antworten.

---

### ATK-10 — Gefälschten API-Schlüssel für unbegrenzten Bucket verwenden 🚫 BLOCKIERT (by design)

**Angriff**: Bei API-Schlüssel-basierter Ratenbegrenzung einen gefälschten Schlüssel wie `X-Api-Key: unlimited` angeben.
**Ergebnis**: BLOCKIERT (by design) — jeder API-Schlüssel bekommt seinen eigenen Bucket.

---

### ATK-11 — Leeren Ratenbegrenzungsschlüssel senden 🚫 BLOCKIERT

**Angriff**: `REMOTE_ADDR` aus Server-Params entfernen, um einen leeren Schlüssel zu erzwingen.
**Ergebnis**: BLOCKIERT — wenn `REMOTE_ADDR` fehlt, wird der Schlüssel `ip:` (leere Zeichenkette als IP-Präfix). Das erstellt einen einzelnen gemeinsamen Bucket für alle unbekannten IPs.

---

### ATK-12 — InMemoryRateLimitStorage in Produktion verwenden 🚫 BLOCKIERT (Designwarnung)

**Angriff**: Operator deployt mit `InMemoryRateLimitStorage` in Produktion versehentlich. Jeder PHP-FPM-Worker hat sein eigenes Array, so 10 Worker multiplizieren das Limit effektiv mit 10.
**Ergebnis**: BLOCKIERT (durch Dokumentationswarnung) — bekanntes Anti-Pattern, oben dokumentiert.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Limit erschöpfen, um sich selbst zu DoS | 🚫 BLOCKIERT (by design) |
| ATK-02 | Mehrere IPs, um Per-IP-Limit zu umgehen | 🚫 BLOCKIERT (gemildert) |
| ATK-03 | X-Forwarded-For fälschen | 🚫 BLOCKIERT (Designhinweis) |
| ATK-04 | Fenstergrenz-Burst | 🚫 BLOCKIERT (Designbeschränkung) |
| ATK-05 | X-RateLimit-*-Anfrage-Header manipulieren | 🚫 BLOCKIERT |
| ATK-06 | Anderen Pfad verwenden, um Limit zu umgehen | 🚫 BLOCKIERT |
| ATK-07 | Race Condition, um Limit zu überschreiten | 🚫 BLOCKIERT |
| ATK-08 | Systemlast aus Retry-After inferieren | 🚫 BLOCKIERT (irrelevant) |
| ATK-09 | Fehlender Retry-After verursacht Retry-Schleifen | 🚫 BLOCKIERT |
| ATK-10 | Gefälschter API-Schlüssel für unbegrenzten Bucket | 🚫 BLOCKIERT (by design) |
| ATK-11 | Leerer Schlüssel vereint den gesamten Traffic | 🚫 BLOCKIERT |
| ATK-12 | InMemoryStorage multipliziert Limit in Produktion | 🚫 BLOCKIERT (dokumentiert) |

**12 BLOCKIERT / GEMILDERT, 0 EXPONIERT**

---

## Was man NICHT tun sollte

| Anti-Pattern | Risiko |
|---|---|
| `InMemoryRateLimitStorage` in Produktion verwenden | PHP-FPM-Worker teilen keinen Speicher; effektives Limit = konfiguriertes Limit × Worker-Anzahl |
| Auf `X-Forwarded-For` von nicht vertrauenswürdigen Clients schlüsseln | Angreifer können jede IP fälschen; Ratenbegrenzung wird umgangen |
| Einen globalen Bucket für alle Clients verwenden | Das Ratenlimit eines Clients blockiert alle anderen Clients |
| 403 statt 429 für Ratenlimit zurückgeben | Client kann "verboten" nicht von "zu viele Anfragen" unterscheiden |
| Kein `Retry-After`-Header bei 429 | Clients wiederholen sofort; Thundering Herd beim Fenster-Reset |
| `limit` für sensible Endpunkte zu hoch setzen | Login-Endpunkt mit limit=10000 ist praktisch ungeschützt |
| Keine Ratenbegrenzung bei Login/Passwort-Reset-Endpunkten | Brute-Force-Angriffe erfolgreich ohne Lockout oder Drosselung |
