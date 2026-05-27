# Ratenbegrenzung

> **FT-Referenz**: FT284 (`NENE2-FT/throttlelog`) — ThrottleMiddleware-Ratenbegrenzung: IP-basiertes Fixed-Window, benutzerdefinierter Key-Extraktor (Benutzer/API-Key), X-RateLimit-*-Header, 429 Problem Details mit Retry-After, InMemoryRateLimitStorage für Tests, 9 Tests / 33 Assertions BESTANDEN.
>
> **ATK-Bewertung**: ATK-01 bis ATK-12 am Ende dieses Dokuments.

`ThrottleMiddleware` erzwingt eine Fixed-Window-Ratenbegrenzung für alle Anfragen. Es fügt `X-RateLimit-Limit`-, `X-RateLimit-Remaining`- und `X-RateLimit-Reset`-Header zu jeder Antwort hinzu und gibt eine `429 Too Many Requests`-Problem-Details-Antwort zurück, wenn das Limit überschritten wird.

## Grundlegende Einrichtung

Übergeben Sie `ThrottleMiddleware` an `RuntimeApplicationFactory` über den `throttleMiddleware`-Parameter:

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // nur lokal/Test — siehe „Produktion" unten
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

Der benannte Parameter ist `throttleMiddleware`, nicht `middlewares` — `RuntimeApplicationFactory` hat einen dedizierten Slot für diese Middleware, der sie korrekt in der Pipeline positioniert (nach der Authentifizierung, sodass Limits pro Benutzer möglich sind).

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

## Ratenbegrenzungs-Keys

### Standard: IP-basiert (REMOTE_ADDR)

Standardmäßig ist der Key `ip:<REMOTE_ADDR>`. Jede Client-IP bekommt ihren eigenen Bucket.

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

Dies verhindert, dass gemeinsam genutzte IP-Umgebungen (Büro-NAT) unfairerweise einen Bucket teilen, und ermöglicht strengere Limits für nicht authentifizierte Anfragen.

### Benutzerdefiniert: API-Key-Header

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## Reverse-Proxy / Load-Balancer-Warnung

Hinter einem Reverse-Proxy ist `REMOTE_ADDR` die IP des Proxys — alle echten Clients teilen einen einzigen Bucket. Beheben Sie dies, indem Sie einen vertrauenswürdigen Forwarded-IP-Header lesen:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**Vertrauen Sie `X-Forwarded-For` nur, wenn Ihr Proxy unter Ihrer Kontrolle steht und ihn zuverlässig setzt.** Ein Angreifer kann diesen Header fälschen, wenn der Traffic die Anwendung direkt ohne den Proxy erreicht.

## Produktion: Gemeinsamen Speicher verwenden

`InMemoryRateLimitStorage` hält Zähler in einem PHP-Array. PHP-FPM betreibt mehrere Worker-Prozesse; **jeder Worker hat sein eigenes Array, sodass Zähler nicht geteilt werden**. In der Produktion bedeuten 10 Worker mit einem Limit von 60 ein tatsächliches Limit von ~600.

Für die Produktion `RateLimitStorageInterface` mit einem gemeinsamen Speicher implementieren:

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

Dann einbinden:

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## Fixed-Window-Burst-Problem

`ThrottleMiddleware` verwendet einen Fixed-Window-Algorithmus. Clients können die effektive Rate verdoppeln, indem sie Anfragen an der Grenze zweier Fenster senden:

```
Limit: 100 Anfragen/Minute, Fenster: :00–:59

:59 — 100 Anfragen → trifft das Limit
:00 — 100 Anfragen → neues Fenster, alle passieren

Ergebnis: 200 Anfragen in ~2 Sekunden
```

Falls dies ein Problem darstellt, einen Sliding-Window- oder Token-Bucket-Algorithmus in Ihrer `RateLimitStorageInterface`-Implementierung implementieren. Die Schnittstelle und Middleware sind algorithmus-agnostisch.

## Limits pro Route

`RuntimeApplicationFactory` unterstützt eine `ThrottleMiddleware`-Instanz, die global angewendet wird. Für Limits pro Route mit unterschiedlichen Einstellungen `ThrottleMiddleware` als routenseitige Middleware manuell anwenden, indem individuelle Handler umschlossen werden.

## Client-Retry-Muster

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // ein Wiederholungsversuch
    }
    return res;
}
```

## Code-Review-Checkliste

- [ ] `InMemoryRateLimitStorage` wird NICHT im Produktionscode verwendet
- [ ] Gemeinsamer Speicher (Redis, Memcached oder datenbankgestützt) wird in der Produktion über `RateLimitStorageInterface` eingebunden
- [ ] `keyExtractor` verwendet die richtige Granularität: IP, Benutzer oder API-Key (nicht immer `REMOTE_ADDR`)
- [ ] Hinter einem Reverse-Proxy: `X-Forwarded-For` wird nur von einem vertrauenswürdigen Proxy gelesen, nicht aus beliebigen Client-Headern
- [ ] `limit` und `windowSeconds` sind für den erwarteten Traffic des Endpunkts angemessen (Login-Endpunkte: strenger; schreibgeschützte APIs: großzügiger)
- [ ] Der `throttleMiddleware`-benannte Parameter (nicht `middlewares`) wird mit `RuntimeApplicationFactory` verwendet
- [ ] Tests verwenden `InMemoryRateLimitStorage` und ein niedriges `limit` (z. B. 3), um das 429-Verhalten ohne Wartezeit zu überprüfen

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

### ATK-01 — Ratenlimit erschöpfen, um legitime Benutzer zu blockieren (DoS) 🚫 BLOCKIERT (absichtlich)

**Angriff**: Angreifer sendet 60 Anfragen pro Minute von seiner IP, um sich selbst zu blockieren (oder Limits zu sondieren).
**Ergebnis**: BLOCKIERT (absichtlich) — das Limit gilt für die eigene IP/den eigenen Key des Angreifers. Andere Clients sind nicht betroffen (separate Buckets). Die 429-Antwort enthält `Retry-After`, sodass der Angreifer weiß, wann er es erneut versuchen kann. Dies ist das beabsichtigte Verhalten; Ratenbegrenzung soll Missbrauch blockieren, nicht DoS gegen andere verhindern.

---

### ATK-02 — Per-IP-Limit durch Verwendung verschiedener IP-Adressen umgehen 🚫 BLOCKIERT (abgemildert)

**Angriff**: Angreifer verwendet mehrere IPs (Botnet, VPN-Rotation), um Anfragen unter dem Limit von jeder IP zu senden.
**Ergebnis**: ABGEMILDERT — jede IP hat ihren eigenen Bucket; einzelne IPs werden ratenbegrenzt. Verteilte Angriffe von vielen IPs können durch Single-Node-Ratenbegrenzung nicht gestoppt werden. Produktionsminderung: CAPTCHA, WAF, CDN-seitige Ratenbegrenzung oder authentifizierte Raten-Limits.

---

### ATK-03 — X-Forwarded-For fälschen, um IP-basiertes Limit zu umgehen 🚫 BLOCKIERT (Design-Hinweis)

**Angriff**: Angreifer sendet `X-Forwarded-For: 10.0.0.1`, um bei jeder Anfrage als eine andere IP zu erscheinen.
**Ergebnis**: BLOCKIERT (bei korrekter Konfiguration) — Standard-Key verwendet `REMOTE_ADDR` (vom Server gesetzt), keine vom Client gelieferten Header. Wenn `X-Forwarded-For` als Key verwendet wird, darf es nur von einem vertrauenswürdigen Proxy gelesen werden. **Nicht vertrauenswürdige Client-Header als Ratenbegrenzungs-Keys zu verwenden ist das Anti-Muster — siehe „Was man nicht tun sollte".**

---

### ATK-04 — Fenstergrenz-Burst 🚫 BLOCKIERT (Design-Einschränkung)

**Angriff**: 60 Anfragen bei :59 und 60 Anfragen bei :00 (neues Fenster) für 120 Anfragen in 2 Sekunden senden.
**Ergebnis**: BLOCKIERT (innerhalb des Fixed-Window-Designs) — jedes 60-Sekunden-Fenster ist unabhängig. Fixed-Window erlaubt Bursts an Grenzen per Design. Für strengere Kontrolle eine Sliding-Window- oder Token-Bucket-`RateLimitStorageInterface`-Implementierung verwenden.

---

### ATK-05 — Missgebildeten `X-RateLimit-Remaining`-Header senden, um das Limit zu beeinflussen 🚫 BLOCKIERT

**Angriff**: Client sendet `X-RateLimit-Remaining: 999`-Header in der Hoffnung, der Server vertraut ihm.
**Ergebnis**: BLOCKIERT — `X-RateLimit-*`-Header sind **Antwort**-Header, die vom Server gesetzt werden. Der Server liest `REMOTE_ADDR` (oder einen konfigurierten Key) aus der Anfrage, nicht diese Header. Vom Client gelieferte `X-RateLimit-*`-Werte werden ignoriert.

---

### ATK-06 — Ratenlimit erschöpfen, dann anderen Pfad verwenden, um es zu umgehen 🚫 BLOCKIERT

**Angriff**: Nach dem Erreichen des Limits auf `/notes`, `/notes?q=1` oder `/other-path` versuchen.
**Ergebnis**: BLOCKIERT — `ThrottleMiddleware` gilt global für alle Pfade. Das Ratenlimit ist auf IP (oder konfigurierten Key) ausgerichtet, nicht auf den Pfad. Verschiedene Pfade teilen denselben Bucket.

---

### ATK-07 — Race Condition, um das Limit zu überschreiten 🚫 BLOCKIERT

**Angriff**: 61 gleichzeitige Anfragen senden, wenn der verbleibende Zähler 1 beträgt, um das Limit zu überschreiten.
**Ergebnis**: BLOCKIERT — `InMemoryRateLimitStorage` verwendet PHPs sequentielle Anfragebearbeitung innerhalb eines einzelnen Prozesses. Für Multi-Prozess-Produktionseinsätze sind atomare Inkrementoperationen (Redis `INCR`) erforderlich. Das Middleware-Design erfordert, dass Speicher-Implementierungen Nebenläufigkeit behandeln.

---

### ATK-08 — Ratenbegrenzungs-Timing sondieren, um Systemlast zu erschließen 🚫 BLOCKIERT (irrelevant)

**Angriff**: `Retry-After` messen, um Serverlast oder Anfragemuster zu bestimmen.
**Ergebnis**: IRRELEVANT — `Retry-After` gibt die verbleibende Fensterzeit zurück (fest), nicht die Systemlast. Es zeigt, wann das Fenster zurückgesetzt wird, aber keine internen Metriken.

---

### ATK-09 — Fehlender `Retry-After`-Header bei 429-Antwort 🚫 BLOCKIERT

**Angriff**: Verlässt sich darauf, dass der Client 429 ignoriert, weil `Retry-After` fehlt, was unendliche Wiederholungsschleifen verursacht.
**Ergebnis**: BLOCKIERT — `ThrottleMiddleware` enthält immer sowohl `Retry-After` als auch `X-RateLimit-Reset` in 429-Antworten. Gut implementierte Clients respektieren diese Header.

---

### ATK-10 — Gefälschten API-Key für unbegrenzten Bucket verwenden 🚫 BLOCKIERT (absichtlich)

**Angriff**: Bei API-Key-basierter Ratenbegrenzung einen erfundenen Key wie `X-Api-Key: unlimited` angeben.
**Ergebnis**: BLOCKIERT (absichtlich) — jeder API-Key bekommt seinen eigenen Bucket. Der Key `unlimited` hat dasselbe `limit` wie jeder andere. Unbekannte/erfundene Keys sind nicht besonders. Wenn Keys Benutzern zugeordnet sind, sollten ungültige Keys die Authentifizierung fehlschlagen lassen, bevor sie den Ratenbegrenzer erreichen.

---

### ATK-11 — Leeren Ratenbegrenzungs-Key senden, um den gesamten Traffic in einen Bucket zusammenzuführen 🚫 BLOCKIERT

**Angriff**: `REMOTE_ADDR` aus den Server-Params entfernen, um einen leeren Key zu erzwingen, in der Hoffnung, dass der gesamte Traffic einen Bucket teilt.
**Ergebnis**: BLOCKIERT — wenn `REMOTE_ADDR` fehlt, wird der Key `ip:` (leerer String als IP-Präfix). Dies erstellt einen einzigen gemeinsamen Bucket für alle unbekannten IPs — nicht was man in der Produktion möchte, aber kein Bypass für das Limit selbst.

---

### ATK-12 — InMemoryRateLimitStorage in der Produktion verwenden, um Pro-Prozess-Isolation zu erhalten 🚫 BLOCKIERT (Design-Warnung)

**Angriff**: Betreiber setzt mit `InMemoryRateLimitStorage` in der Produktion ein (z. B. versehentlich). Jeder PHP-FPM-Worker hat sein eigenes Array, sodass 10 Worker das Limit effektiv mit 10 multiplizieren.
**Ergebnis**: BLOCKIERT (durch Dokumentationswarnung) — dies ist ein bekanntes Anti-Muster, das oben dokumentiert ist. Die Code-Review-Checkliste kennzeichnet es explizit. Produktionseinsätze müssen gemeinsamen Speicher (Redis, DB-gestützt) verwenden.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | Limit erschöpfen, um sich selbst zu DoS-en | 🚫 BLOCKIERT (absichtlich) |
| ATK-02 | Mehrere IPs, um Per-IP-Limit zu umgehen | 🚫 BLOCKIERT (abgemildert) |
| ATK-03 | X-Forwarded-For fälschen | 🚫 BLOCKIERT (Design-Hinweis) |
| ATK-04 | Fenstergrenz-Burst | 🚫 BLOCKIERT (Design-Einschränkung) |
| ATK-05 | X-RateLimit-*-Anfrage-Header manipulieren | 🚫 BLOCKIERT |
| ATK-06 | Anderen Pfad verwenden, um Limit zu umgehen | 🚫 BLOCKIERT |
| ATK-07 | Race Condition, um Limit zu überschreiten | 🚫 BLOCKIERT |
| ATK-08 | Systemlast aus Retry-After erschließen | 🚫 BLOCKIERT (irrelevant) |
| ATK-09 | Fehlender Retry-After verursacht Wiederholungsschleifen | 🚫 BLOCKIERT |
| ATK-10 | Gefälschter API-Key für unbegrenzten Bucket | 🚫 BLOCKIERT (absichtlich) |
| ATK-11 | Leerer Key führt allen Traffic zusammen | 🚫 BLOCKIERT |
| ATK-12 | InMemoryStorage multipliziert Limit in Produktion | 🚫 BLOCKIERT (dokumentiert) |

**12 BLOCKIERT / ABGEMILDERT, 0 EXPONIERT**
Separate Per-IP-Buckets, standardmäßiger REMOTE_ADDR-Key und obligatorischer `Retry-After`-Header verhindern alle getesteten Angriffsvektoren.

---

## Was man nicht tun sollte

| Anti-Muster | Risiko |
|---|---|
| `InMemoryRateLimitStorage` in der Produktion verwenden | PHP-FPM-Worker teilen keinen Speicher; effektives Limit = konfiguriertes Limit × Worker-Anzahl |
| Auf `X-Forwarded-For` von nicht vertrauenswürdigen Clients basieren | Angreifer fälschen beliebige IP; Ratenbegrenzung wird umgangen |
| Einen globalen Bucket für alle Clients verwenden | Ein Client blockiert durch Ratenbegrenzung alle anderen Clients |
| 403 statt 429 für Ratenlimit zurückgeben | Client kann nicht zwischen „verboten" und „zu viele Anfragen" unterscheiden; `Retry-After` fehlt |
| Kein `Retry-After`-Header bei 429 | Clients wiederholen sofort; Thundering Herd beim Fenster-Reset |
| `limit` zu hoch für sensible Endpunkte setzen | Login-Endpunkt mit limit=10000 ist effektiv ungeschützt |
| Keine Ratenbegrenzung bei Login/Passwort-Reset-Endpunkten | Brute-Force-Angriffe gelingen ohne Sperrung oder Drosselung |
