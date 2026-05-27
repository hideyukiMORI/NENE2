# Implementierungsanleitung für Application-Caching

## Übersicht

Diese Anleitung erklärt, wie Sie mit NENE2 Application-Caching implementieren.
Sie umfasst das Cache-Aside-Muster, TTL-basierte Ablaufzeiten, Invalidierung bei Schreibvorgängen und Statistiken als REST-API.

---

## Endpunkt-Design

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/products` | Produktliste (mit Cache) |
| GET | `/products/{id}` | Produktdetail (mit Cache) |
| POST | `/products` | Produkt erstellen (Liste-Cache invalidieren) |
| PUT | `/products/{id}` | Produkt aktualisieren (Einzel + Liste invalidieren) |
| DELETE | `/products/{id}` | Produkt löschen (Einzel + Liste invalidieren) |
| POST | `/cache/clear` | Gesamten Cache löschen |
| GET | `/cache/stats` | Trefferanzahl, Fehlanzahl, Eintragsanzahl |

---

## Cache-Interface

```php
interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 60): void;
    public function delete(string $key): void;
    public function flush(): void;
    /** @return array{hits: int, misses: int, size: int} */
    public function stats(): array;
}
```

---

## InMemoryCache (Cache mit TTL)

```php
class InMemoryCache implements CacheInterface
{
    private array $store  = [];
    private array $expiry = [];
    private int $hits     = 0;
    private int $misses   = 0;

    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null, // Uhr-Injektion für Tests
    ) {}

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            $this->misses++;
            return null;
        }
        if ($this->expiry[$key] <= $this->now()) {
            unset($this->store[$key], $this->expiry[$key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $this->store[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $this->store[$key]  = $value;
        $this->expiry[$key] = $this->now() + $effectiveTtl;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
```

---

## Design-Schwerpunkte

### Cache-Aside-Muster

Beim Lesen den Cache prüfen; bei einem Fehltreffer aus der DB holen und im Cache speichern:

```php
public function handleGet(int $id): ResponseInterface
{
    $key = "product:{$id}";

    $cached = $this->cache->get($key);
    if ($cached !== null) {
        return $this->json->create(array_merge($cached, ['cached' => true]));
    }

    $product = $this->repo->find($id);
    if ($product === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    $this->cache->set($key, $product);
    return $this->json->create(array_merge($product, ['cached' => false]));
}
```

### Invalidierung bei Schreibvorgängen

Nach create/update/delete den zugehörigen Cache löschen, damit beim nächsten Lesevorgang frische Daten aus der DB geholt werden:

```php
// POST /products
$this->cache->delete('products:list');

// PUT /products/{id}
$this->cache->delete("product:{$id}");
$this->cache->delete('products:list');

// DELETE /products/{id}
$this->cache->delete("product:{$id}");
$this->cache->delete('products:list');
```

### Cache-Schlüssel-Design

| Muster | Schlüsselbeispiel |
|---|---|
| Einzelne Ressource | `product:42` |
| Kollektion | `products:list` |
| Mit Filter | `products:category:3:page:2` |
| Benutzerbezogen | `user:7:cart` |

Für Kollektions-Caches bei **häufigen Schreibvorgängen** eine **kurze TTL** setzen oder die Strategie **Löschen bei Schreibvorgang** wählen.

### TTL-Design-Richtlinien

| Datencharakter | Empfohlene TTL |
|---|---|
| Statische Stammdaten (z.B. Kategorien) | 5–60 Minuten |
| Bestand/Preis (mittlere Aktualisierungsfrequenz) | 30–60 Sekunden |
| Benutzersitzungsbezogene Daten | Nach Bedarf bis zum Sitzungsablauf |
| Echtzeit-Daten | Nicht cachen |

### Tests: TTL mit Uhr-Injektion simulieren

Unabhängig von `time()` lässt sich die Zeit über eine injizierte Uhr steuern:

```php
$time  = time();
$clock = function () use (&$time): int { return $time; };
$app   = AppFactory::createSqlite($dbFile, $clock);

$this->req('GET', '/products/1'); // Cache befüllen (TTL=60s)

$time += 61; // Uhr über TTL hinaus vorspulen

$res = $this->req('GET', '/products/1');
$this->assertFalse($data['cached']); // abgelaufen → Cache-Fehltreffer
```

PHPs `static fn()` unterstützt keine Referenz-Captures, daher `function () use (&$time)` verwenden.

### Cache-Statistiken für Observability

```php
GET /cache/stats
→ {"hits": 42, "misses": 8, "size": 5}
```

Trefferrate = hits / (hits + misses). In der Produktion kann der Export zu Prometheus/StatsD die Cache-Effektivität kontinuierlich messen.

---

## Antwortbeispiele

### GET /products (1. Aufruf — Cache-Fehltreffer)

```json
{
  "products": [{"id": 1, "name": "Widget", "price": 9.99, "stock": 10}],
  "cached": false
}
```

### GET /products (2. Aufruf — Cache-Treffer)

```json
{
  "products": [...],
  "cached": true
}
```

### GET /cache/stats

```json
{
  "hits": 5,
  "misses": 2,
  "size": 3
}
```

---

## Referenzimplementierung

`../NENE2-FT/cachelog/` — FT161 Field Trial (20 Tests, TTL-Ablauf, Schreibinvalidierung, Statistiken)
