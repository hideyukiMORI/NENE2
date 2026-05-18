# Adicionar limitação de taxa

Este guia mostra como proteger sua aplicação NENE2 com limitação de taxa de requisições usando
`ThrottleMiddleware` e `RateLimitStorageInterface`.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando. Caso contrário, comece pelo [Tutorial](../tutorial/first-api.md).

---

## Início rápido

Adicione `ThrottleMiddleware` ao `RuntimeApplicationFactory`. O `InMemoryRateLimitStorage` embutido
é adequado para desenvolvimento local e implantações de processo único.

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
    limit:          60,   // requisições permitidas por janela
    windowSeconds:  60,   // duração da janela em segundos
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle,
))->create();
```

`ThrottleMiddleware` fica na posição 8 na pilha de middlewares — após a autenticação,
portanto você pode definir limites por usuário autenticado, se desejar (veja Extrator de chave personalizado abaixo).

---

## Como funciona

Para cada requisição, o middleware:

1. Calcula uma chave para o cliente (padrão: `REMOTE_ADDR`).
2. Incrementa o contador no backend de armazenamento.
3. Se o contador estiver **igual ou abaixo** do limite — passa a requisição adiante e adiciona cabeçalhos de limite de taxa.
4. Se o contador **exceder** o limite — retorna `429 Too Many Requests` com Problem Details.

### Cabeçalhos de resposta

Toda resposta (incluindo 429) contém estes cabeçalhos:

| Cabeçalho | Valor |
|---|---|
| `X-RateLimit-Limit` | Limite configurado por janela |
| `X-RateLimit-Remaining` | Requisições restantes na janela atual |
| `X-RateLimit-Reset` | Timestamp Unix de quando a janela será redefinida |
| `Retry-After` | Segundos até a redefinição da janela (somente 429) |

### Corpo da resposta 429

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

## Extrator de chave personalizado

Por padrão, a chave é o endereço IP do cliente (`REMOTE_ADDR`). Passe uma `Closure` para definir limites
por usuário autenticado, chave de API ou qualquer outra dimensão.

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

## Trocar o backend de armazenamento

`InMemoryRateLimitStorage` armazena contadores na memória do processo PHP. Eles são reiniciados a cada
requisição em implantações FPM e **não são compartilhados entre processos**. Para produção, você precisa
de um armazenamento compartilhado como Redis.

Implemente `RateLimitStorageInterface`:

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

## Decisões de design

Consulte [ADR 0010](/adr/0010-rate-limiting) para a justificativa por trás de:
- Seleção do algoritmo de janela fixa
- Padrão de chave por IP
- Convenções de cabeçalhos (`X-RateLimit-*`, `Retry-After`)
- Fronteira de abstração `RateLimitStorageInterface`

---

## Próximo passo

Consulte [Tipos Problem Details](../reference/problem-details-types.md) para o formato completo do erro `429`,
ou [Adicionar um health check](./add-health-check.md) para a funcionalidade de observabilidade complementar.
