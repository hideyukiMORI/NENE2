# Guia de Implementação de Cache de Aplicação

## Visão Geral

Este guia explica como implementar cache de aplicação com NENE2.
Cobre o padrão Cache-Aside (look-aside), expiração baseada em TTL, invalidação na escrita e estatísticas via REST API.

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| GET | `/products` | Listar produtos (com cache) |
| GET | `/products/{id}` | Detalhe do produto (com cache) |
| POST | `/products` | Criar produto (invalida cache da lista) |
| PUT | `/products/{id}` | Atualizar produto (invalida individual + lista) |
| DELETE | `/products/{id}` | Remover produto (invalida individual + lista) |
| POST | `/cache/clear` | Limpar todo o cache |
| GET | `/cache/stats` | Número de hits, misses e entradas |

---

## Interface de Cache

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

## InMemoryCache (Cache com TTL)

```php
class InMemoryCache implements CacheInterface
{
    private array $store  = [];
    private array $expiry = [];
    private int $hits     = 0;
    private int $misses   = 0;

    public function __construct(
        private readonly int $defaultTtl = 60,
        private readonly ?\Closure $clock = null, // injeção de clock para testes
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

## Pontos Principais de Design

### Padrão Cache-Aside

Verifique o cache na leitura; se não encontrar, busque no banco de dados e salve no cache:

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

### Invalidação na Escrita

Após criar/atualizar/deletar, remova o cache relacionado para que a próxima leitura busque dados frescos do banco de dados:

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

### Design de Chaves de Cache

| Padrão | Exemplo de chave |
|---|---|
| Recurso único | `product:42` |
| Coleção | `products:list` |
| Com filtro | `products:category:3:page:2` |
| Escopo por usuário | `user:7:cart` |

Para cache de coleção com **muitas escritas**, defina um TTL curto ou adote a política de **deletar na escrita**.

### Diretrizes de TTL

| Característica do dado | TTL recomendado |
|---|---|
| Dados master estáticos (categorias etc.) | 5 a 60 minutos |
| Estoque / preço (frequência de atualização média) | 30 a 60 segundos |
| Dados relacionados a sessão de usuário | Até o término da sessão conforme necessário |
| Dados que exigem tempo real | Não cachear |

### Testes: Simular TTL com Injeção de Clock

Controle o tempo com um clock injetado sem depender do `time()` real:

```php
$time  = time();
$clock = function () use (&$time): int { return $time; };
$app   = AppFactory::createSqlite($dbFile, $clock);

$this->req('GET', '/products/1'); // aquecer cache (TTL=60s)

$time += 61; // avançar clock além do TTL

$res = $this->req('GET', '/products/1');
$this->assertFalse($data['cached']); // expirado → cache miss
```

Como `static fn()` do PHP não suporta captura por referência, use `function () use (&$time)`.

### Observabilidade com Estatísticas de Cache

```php
GET /cache/stats
→ {"hits": 42, "misses": 8, "size": 5}
```

Taxa de hit = hits / (hits + misses). Em produção, exporte para Prometheus/StatsD para medir continuamente a eficácia do cache.

---

## Exemplos de Resposta

### GET /products (1ª vez — cache miss)

```json
{
  "products": [{"id": 1, "name": "Widget", "price": 9.99, "stock": 10}],
  "cached": false
}
```

### GET /products (2ª vez — cache hit)

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

## Implementação de Referência

`../NENE2-FT/cachelog/` — Field Trial FT161 (20 testes, expiração por TTL, invalidação na escrita, estatísticas)
