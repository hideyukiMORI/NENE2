# Guide d'implémentation du cache applicatif

## Vue d'ensemble

Ce guide explique comment implémenter un cache applicatif avec NENE2.
Il couvre le pattern Cache-Aside (lookaside), l'expiration basée sur le TTL, l'invalidation à l'écriture et les statistiques exposées comme API REST.

---

## Conception des endpoints

| Méthode | Chemin | Description |
|---|---|---|
| GET | `/products` | Liste des produits (avec cache) |
| GET | `/products/{id}` | Détail d'un produit (avec cache) |
| POST | `/products` | Créer un produit (invalide le cache de liste) |
| PUT | `/products/{id}` | Mettre à jour un produit (invalide individuel + liste) |
| DELETE | `/products/{id}` | Supprimer un produit (invalide individuel + liste) |
| POST | `/cache/clear` | Vider tout le cache |
| GET | `/cache/stats` | Nombre de hits, misses, entrées |

---

## Interface de cache

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

## InMemoryCache (cache avec TTL)

```php
class InMemoryCache implements CacheInterface
{
    private array $store  = [];
    private array $expiry = [];
    private int $hits     = 0;
    private int $misses   = 0;

    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null, // injection d'horloge pour les tests
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

## Points clés de conception

### Pattern Cache-Aside

Vérifier le cache à la lecture, et en cas de miss, récupérer depuis la DB et stocker dans le cache :

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

### Invalidation à l'écriture

Après create/update/delete, supprimer les caches associés pour que la prochaine lecture récupère des données fraîches depuis la DB :

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

### Conception des clés de cache

| Pattern | Exemple de clé |
|---|---|
| Ressource unique | `product:42` |
| Collection | `products:list` |
| Avec filtre | `products:category:3:page:2` |
| Scopé par utilisateur | `user:7:cart` |

Pour les caches de collection, choisir entre **TTL court si les écritures sont fréquentes** ou **suppression à l'écriture**.

### Directives de conception du TTL

| Nature des données | TTL recommandé |
|---|---|
| Données maîtres statiques (catégories, etc.) | 5 à 60 minutes |
| Stock, prix (fréquence de mise à jour modérée) | 30 à 60 secondes |
| Données liées aux sessions utilisateur | Jusqu'à l'expiration de la session selon les besoins |
| Données nécessitant du temps réel | Ne pas mettre en cache |

### Tests : simulation du TTL par injection d'horloge

Sans dépendre du `time()` réel, contrôler l'heure avec une horloge injectée :

```php
$time  = time();
$clock = function () use (&$time): int { return $time; };
$app   = AppFactory::createSqlite($dbFile, $clock);

$this->req('GET', '/products/1'); // réchauffer le cache (TTL=60s)

$time += 61; // avancer l'horloge au-delà du TTL

$res = $this->req('GET', '/products/1');
$this->assertFalse($data['cached']); // expiré → cache miss
```

Utiliser `function () use (&$time)` car `static fn()` de PHP ne supporte pas la capture par référence.

### Observabilité avec les statistiques de cache

```php
GET /cache/stats
→ {"hits": 42, "misses": 8, "size": 5}
```

Taux de hit = hits / (hits + misses). En production, exporter vers Prometheus/StatsD pour
mesurer en continu l'efficacité du cache.

---

## Exemples de réponse

### GET /products (1ère fois — cache miss)

```json
{
  "products": [{"id": 1, "name": "Widget", "price": 9.99, "stock": 10}],
  "cached": false
}
```

### GET /products (2ème fois — cache hit)

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

## Implémentation de référence

`../NENE2-FT/cachelog/` — Field Trial FT161 (20 tests, expiration TTL, invalidation à l'écriture, statistiques)
