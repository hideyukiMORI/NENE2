# Como Fazer: Rate Limiter de Janela Fixa

> **Referência FT**: FT251 (`NENE2-FT/ratelimitlog`) — Rate limiting de janela fixa com upsert SQLite

Demonstra um rate limiter de janela fixa armazenado no SQLite. Cada par `(key, window_start)`
acumula uma contagem de requisições. Quando a contagem excede o limite configurado, a requisição é
rejeitada com `429 Too Many Requests` e um header `Retry-After`.

---

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/ping` | Endpoint com rate limiting (lê `X-Client-Key`) |
| `GET` | `/status` | Contador somente-leitura para uma chave (`?key=`) |

---

## Schema: chave primária composta como armazenamento do contador de rate limit

```sql
CREATE TABLE IF NOT EXISTS rate_limit_windows (
    key          TEXT    NOT NULL,
    window_start TEXT    NOT NULL, -- timestamp ISO 8601 truncado para o limite da janela
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, window_start)
);

CREATE INDEX IF NOT EXISTS idx_rl_key_window ON rate_limit_windows(key, window_start);
```

`PRIMARY KEY(key, window_start)` identifica uniquely um contador para cada par `(cliente, janela)`.
O índice torna a busca do upsert rápida. Uma tabela de log `api_calls` separada registra cada
requisição bem-sucedida para fins de auditoria.

---

## Padrão de upsert: `INSERT … ON CONFLICT DO UPDATE`

```php
$this->executor->execute(
    'INSERT INTO rate_limit_windows (key, window_start, count) VALUES (?, ?, 1)
     ON CONFLICT(key, window_start) DO UPDATE SET count = count + 1',
    [$key, $windowStart],
);
```

A primeira requisição para um par `(key, windowStart)` insere `count = 1`. Requisições
subsequentes dentro da mesma janela incrementam atomicamente via `DO UPDATE SET count = count + 1`.
Nenhum `SELECT` antes do `INSERT` é necessário — o upsert é atômico no SQLite.

Após o upsert, o contador é lido para detectar se o limite foi excedido:

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

A verificação é acionada **após** o incremento. Isso significa que a (limite+1)-ésima requisição é contada
antes de ser rejeitada — o contador chega a `limit + 1` para requisições que excedem o limite.
Isso é intencional: a contagem reflete com precisão o total de tentativas, não apenas as permitidas.

---

## Truncamento de janela: limite fixo

```php
private function truncateToWindow(string $now): string
{
    $ts     = strtotime($now);
    $bucket = (int) ($ts - ($ts % $this->windowSeconds));

    return date('Y-m-d\TH:i:s\Z', $bucket);
}
```

`$ts % $windowSeconds` é o offset dentro da janela atual. Subtraí-lo dá o timestamp de início da
janela. Para uma janela de 60 segundos em `2026-01-01T00:00:45Z`:

```
ts     = 1751328045  (Unix timestamp)
ts % 60 = 45
bucket = 1751328045 - 45 = 1751328000  → 2026-01-01T00:00:00Z
```

Todas as requisições de `:00` a `:59` compartilham o mesmo `window_start = 2026-01-01T00:00:00Z`.
Em `:60` uma nova janela começa e o contador reseta.

**Trade-off janela fixa vs deslizante**:

| Propriedade | Janela fixa | Janela deslizante |
|---|---|---|
| Implementação | Um único upsert por requisição | Múltiplas leituras/escritas entre buckets |
| Memória | 1 linha por (key, janela) | N linhas por key (sub-buckets) |
| Burst na fronteira | Sim — 2× o limite possível na borda da janela | Não — limita suavemente ao longo do tempo |
| Uso comum | APIs simples, ferramentas internas | APIs públicas, fairness estrita |

---

## `429 Too Many Requests` com `Retry-After`

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

A exceção carrega `retryAfter` (segundos até a janela atual expirar). O handler
mapeia isso para uma resposta Problem Details `429` com um header `Retry-After`:

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

`Retry-After` é o número de segundos que o cliente deve aguardar antes de tentar novamente.
É calculado como `windowEnd - now`, limitado a `>= 0`.

---

## Chave por cliente via header `X-Client-Key`

```php
$key = $request->getHeaderLine('X-Client-Key') ?: '127.0.0.1';
```

Cada cliente é identificado pelo seu header `X-Client-Key`. Header ausente volta para
`'127.0.0.1'` — todos os clientes não autenticados compartilham um contador. Em produção:

- Use um user ID verificado ou API key extraída de uma sessão autenticada — não um
  header que o cliente pode falsificar.
- Use `$_SERVER['REMOTE_ADDR']` (após remoção de proxy) para limitação por IP.
- Nunca use `X-Forwarded-For` diretamente — um cliente pode falsificá-lo para bypass dos limites.

---

## Endpoint de status somente-leitura

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

`GET /status?key=xxx` retorna o contador atual sem incrementá-lo.
Usado para dashboards de monitoramento ou lógica de backoff no cliente.

---

## Limpeza de janelas expiradas

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

Janelas antigas se acumulam com o tempo. `pruneExpired()` deleta linhas mais antigas que duas
durações de janela atrás (janela atual + janela anterior são mantidas; mais antigas são removidas).

Execute `pruneExpired()` de uma tarefa em background ou após cada requisição (com amostragem —
ex.: `rand(0, 99) === 0` para executar em ~1% das requisições):

```php
if (random_int(0, 99) === 0) {
    $this->limiter->pruneExpired($now);
}
```

---

## Injeção de configuração

```php
$limiter = new SqliteRateLimiter($executor, limit: 3, windowSeconds: 60);
```

`limit` e `windowSeconds` são injetados na construção. Endpoints diferentes podem usar
instâncias de limiter diferentes com configurações diferentes:

```php
$globalLimiter = new SqliteRateLimiter($executor, limit: 100, windowSeconds: 60);
$strictLimiter = new SqliteRateLimiter($executor, limit: 5,   windowSeconds: 60);
```

---

## Howtos relacionados

- [`rate-limiting.md`](rate-limiting.md) — `ThrottleMiddleware` para rate limiting por rota
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — janela deslizante com sub-buckets (ratelog FT200)
- [`add-rate-limiting.md`](add-rate-limiting.md) — adicionando rate limiting a uma rota existente
- [`quota-management.md`](quota-management.md) — cotas de horizonte maior (diário, mensal)
- [`api-usage-metering.md`](api-usage-metering.md) — rastreamento de uso por usuário com verificação de cota
