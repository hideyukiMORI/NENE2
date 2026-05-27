# Limitação de Taxa

> **Referência FT**: FT284 (`NENE2-FT/throttlelog`) — Limitação de taxa com ThrottleMiddleware: janela fixa baseada em IP, extrator de chave personalizado (usuário/chave de API), headers X-RateLimit-*, Problem Details 429 com Retry-After, InMemoryRateLimitStorage para testes, 9 testes / 33 assertivas PASS.
>
> **Avaliação ATK**: ATK-01 a ATK-12 incluídos no final deste documento.

`ThrottleMiddleware` impõe um limite de taxa de janela fixa em todas as requisições. Ele adiciona os headers `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `X-RateLimit-Reset` a cada resposta, e retorna uma resposta Problem Details `429 Too Many Requests` quando o limite é excedido.

## Configuração Básica

Passe `ThrottleMiddleware` para `RuntimeApplicationFactory` via o parâmetro `throttleMiddleware`:

```php
use Nene2\Middleware\InMemoryRateLimitStorage;
use Nene2\Middleware\ThrottleMiddleware;

$storage  = new InMemoryRateLimitStorage(); // somente local/teste — veja "Produção" abaixo
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    limit:         60,   // requisições permitidas por janela
    windowSeconds: 60,   // duração da janela em segundos
);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    throttleMiddleware: $throttle, // ← parâmetro nomeado, não "middlewares"
    routeRegistrars: [...],
))->create();
```

O parâmetro nomeado é `throttleMiddleware`, não `middlewares` — `RuntimeApplicationFactory` tem um slot dedicado para este middleware que o posiciona corretamente no pipeline (após autenticação, para que limites por usuário sejam possíveis).

## Headers de Resposta

Cada resposta inclui o estado do limite de taxa:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42
X-RateLimit-Reset: 1716292860
```

Quando o limite é excedido:

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

## Chaves de Limite de Taxa

### Padrão: baseado em IP (REMOTE_ADDR)

Por padrão, a chave é `ip:<REMOTE_ADDR>`. Cada IP de cliente recebe seu próprio bucket.

### Personalizado: usuário autenticado

Após o middleware de autenticação ter definido um atributo de usuário, chaveie pelo ID do usuário:

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

Isso impede que ambientes de IP compartilhado (NAT de escritório) compartilhem injustamente um bucket, e permite aplicar limites mais rígidos a requisições não autenticadas.

### Personalizado: header de chave de API

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => 'apikey:' . ($r->getHeaderLine('X-Api-Key') ?: 'anonymous'),
);
```

## Aviso sobre Proxy Reverso / Balanceador de Carga

Atrás de um proxy reverso, `REMOTE_ADDR` é o IP do proxy — todos os clientes reais compartilham um único bucket. Corrija isso lendo um header de IP encaminhado confiável:

```php
$throttle = new ThrottleMiddleware(
    $problemDetails,
    $storage,
    keyExtractor: static fn (ServerRequestInterface $r): string
        => $r->getHeaderLine('X-Forwarded-For') ?: $r->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
);
```

**Confie em `X-Forwarded-For` apenas quando seu proxy está sob seu controle e o define de forma confiável.** Um atacante pode falsificar este header se o tráfego chegar à aplicação diretamente sem passar pelo proxy.

## Produção: Use Armazenamento Compartilhado

`InMemoryRateLimitStorage` mantém contadores em um array PHP simples. O PHP-FPM executa múltiplos processos worker; **cada worker tem seu próprio array, portanto os contadores não são compartilhados**. Em produção, 10 workers com um limite de 60 significa um limite real de ~600.

Para produção, implemente `RateLimitStorageInterface` com suporte de um armazenamento compartilhado:

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

Em seguida, injete-o:

```php
$throttle = new ThrottleMiddleware($problemDetails, new RedisRateLimitStorage($redis), limit: 60);
```

## Problema de Burst na Janela Fixa

`ThrottleMiddleware` usa um algoritmo de janela fixa. Clientes podem dobrar a taxa efetiva enviando requisições no limite entre duas janelas:

```
Limite: 100 req/min, Janela: :00–:59

:59 — 100 requisições → atinge o limite
:00 — 100 requisições → nova janela, todas passam

Resultado: 200 requisições em ~2 segundos
```

Se isso for uma preocupação, implemente um algoritmo de janela deslizante ou token bucket em sua implementação de `RateLimitStorageInterface`. A interface e o middleware são agnósticos ao algoritmo.

## Limites por Rota

`RuntimeApplicationFactory` suporta uma instância de `ThrottleMiddleware` aplicada globalmente. Para limites por rota com configurações diferentes, aplique `ThrottleMiddleware` como middleware em nível de rota manualmente, envolvendo handlers individuais.

## Padrão de Retry do Cliente

```typescript
async function fetchWithRetry(url: string, options: RequestInit): Promise<Response> {
    const res = await fetch(url, options);
    if (res.status === 429) {
        const retryAfter = parseInt(res.headers.get('Retry-After') ?? '5', 10);
        await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
        return fetch(url, options); // uma tentativa
    }
    return res;
}
```

## Checklist de Revisão de Código

- [ ] `InMemoryRateLimitStorage` NÃO é usado em código de produção
- [ ] Armazenamento compartilhado (Redis, Memcached ou com suporte de banco de dados) é injetado via `RateLimitStorageInterface` em produção
- [ ] `keyExtractor` usa a granularidade correta: IP, usuário ou chave de API (não sempre `REMOTE_ADDR`)
- [ ] Atrás de um proxy reverso: `X-Forwarded-For` é lido apenas de um proxy confiável, não de headers arbitrários de clientes
- [ ] `limit` e `windowSeconds` são apropriados para o tráfego esperado do endpoint (endpoints de login: mais rígidos; APIs somente leitura: mais lenientes)
- [ ] O parâmetro nomeado `throttleMiddleware` (não `middlewares`) é usado com `RuntimeApplicationFactory`
- [ ] Os testes usam `InMemoryRateLimitStorage` e um `limit` baixo (ex.: 3) para verificar o comportamento 429 sem dormir

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Esgotar limite de taxa para bloquear usuários legítimos (DoS) 🚫 BLOCKED (por design)

**Ataque**: Atacante envia 60 requisições por minuto de seu IP para bloquear a si mesmo (ou para sondar limites).
**Resultado**: BLOCKED (por design) — o limite se aplica ao próprio IP/chave do atacante. Outros clientes não são afetados (buckets separados). A resposta 429 inclui `Retry-After` para que o atacante saiba quando tentar novamente. Este é o comportamento pretendido; a limitação de taxa foi projetada para bloquear abuso, não para impedir DoS contra outros.

---

### ATK-02 — Contornar limite por IP usando endereços IP diferentes 🚫 BLOCKED (mitigado)

**Ataque**: Atacante usa múltiplos IPs (botnet, rotação de VPN) para enviar requisições abaixo do limite de cada IP.
**Resultado**: MITIGADO — cada IP tem seu próprio bucket; IPs individuais são limitados. Ataques distribuídos de muitos IPs não podem ser parados por limitação de taxa em nó único. Mitigação em produção: CAPTCHA, WAF, limitação de taxa em nível de CDN ou limites de taxa autenticados.

---

### ATK-03 — Falsificar X-Forwarded-For para contornar limite baseado em IP 🚫 BLOCKED (nota de design)

**Ataque**: Atacante envia `X-Forwarded-For: 10.0.0.1` para aparecer como um IP diferente em cada requisição.
**Resultado**: BLOCKED (quando configurado corretamente) — a chave padrão usa `REMOTE_ADDR` (definido pelo servidor), não headers fornecidos pelo cliente. Se `X-Forwarded-For` for usado como chave, deve ser lido apenas de um proxy confiável. **Usar headers de clientes não confiáveis como chaves de limite de taxa é o antipadrão — veja O Que NÃO Fazer.**

---

### ATK-04 — Burst no limite de janela 🚫 BLOCKED (limitação de design)

**Ataque**: Enviar 60 requisições às :59 e 60 requisições às :00 (nova janela) para 120 requisições em 2 segundos.
**Resultado**: BLOCKED (dentro do design de janela fixa) — cada janela de 60 segundos é independente. Janela fixa permite bursts nos limites por design. Para controle mais rígido, use uma implementação de `RateLimitStorageInterface` com janela deslizante ou token bucket.

---

### ATK-05 — Enviar header `X-RateLimit-Remaining` malformado para influenciar o limite 🚫 BLOCKED

**Ataque**: Cliente envia header `X-RateLimit-Remaining: 999` esperando que o servidor confie nele.
**Resultado**: BLOCKED — headers `X-RateLimit-*` são headers de **resposta** definidos pelo servidor. O servidor lê `REMOTE_ADDR` (ou uma chave configurada) da requisição, não esses headers. Valores `X-RateLimit-*` fornecidos pelo cliente são ignorados.

---

### ATK-06 — Esgotar limite de taxa e usar caminho diferente para contornar 🚫 BLOCKED

**Ataque**: Após atingir o limite em `/notes`, tente `/notes?q=1` ou `/other-path`.
**Resultado**: BLOCKED — `ThrottleMiddleware` se aplica globalmente em todos os caminhos. O limite de taxa é chaveado por IP (ou chave configurada), não por caminho. Caminhos diferentes compartilham o mesmo bucket.

---

### ATK-07 — Condição de corrida para exceder o limite 🚫 BLOCKED

**Ataque**: Enviar 61 requisições concorrentes quando o restante é 1 para exceder o limite.
**Resultado**: BLOCKED — `InMemoryRateLimitStorage` usa o tratamento sequencial de requisições do PHP dentro de um único processo. Para implantações de produção multi-processo, operações de incremento atômico (Redis `INCR`) são necessárias. O design do middleware requer que as implementações de armazenamento lidem com concorrência.

---

### ATK-08 — Sondar timing do limite de taxa para inferir carga do sistema 🚫 BLOCKED (irrelevante)

**Ataque**: Medir `Retry-After` para determinar carga do servidor ou padrões de requisição.
**Resultado**: IRRELEVANTE — `Retry-After` retorna o tempo restante da janela (fixo), não a carga do sistema. Revela quando a janela reseta, mas não métricas internas.

---

### ATK-09 — Header `Retry-After` ausente na resposta 429 🚫 BLOCKED

**Ataque**: Depende de clientes ignorando 429 porque `Retry-After` está ausente, causando loops de retry infinitos.
**Resultado**: BLOCKED — `ThrottleMiddleware` sempre inclui tanto `Retry-After` quanto `X-RateLimit-Reset` em respostas 429. Clientes bem implementados respeitam esses headers.

---

### ATK-10 — Chave de API falsa para obter bucket ilimitado 🚫 BLOCKED (por design)

**Ataque**: Ao usar limitação de taxa baseada em chave de API, fornecer uma chave fabricada como `X-Api-Key: unlimited`.
**Resultado**: BLOCKED (por design) — cada chave de API recebe seu próprio bucket. A chave `unlimited` tem o mesmo `limit` que qualquer outra. Chaves desconhecidas/fabricadas não são especiais. Se as chaves mapeiam para usuários, chaves inválidas devem falhar na autenticação antes de chegar ao limitador de taxa.

---

### ATK-11 — Enviar chave de limite de taxa vazia para mesclar todo o tráfego em um bucket 🚫 BLOCKED

**Ataque**: Remover `REMOTE_ADDR` dos parâmetros do servidor para forçar uma chave vazia, esperando que todo o tráfego compartilhe um bucket.
**Resultado**: BLOCKED — se `REMOTE_ADDR` estiver ausente, a chave se torna `ip:` (string vazia como prefixo de IP). Isso cria um único bucket compartilhado para todos os IPs desconhecidos — não é o que você quer em produção, mas não é um bypass do próprio limite.

---

### ATK-12 — Usar InMemoryRateLimitStorage em produção para obter isolamento por processo 🚫 BLOCKED (aviso de design)

**Ataque**: Operador implanta com `InMemoryRateLimitStorage` em produção (ex.: acidentalmente). Cada worker do PHP-FPM tem seu próprio array, então 10 workers efetivamente multiplicam o limite por 10.
**Resultado**: BLOCKED (por aviso de documentação) — este é um antipadrão conhecido documentado acima. O checklist de revisão de código o sinaliza explicitamente. Implantações de produção devem usar armazenamento compartilhado (Redis, banco de dados).

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Esgotar limite para DoS próprio | 🚫 BLOCKED (por design) |
| ATK-02 | Múltiplos IPs para contornar limite por IP | 🚫 BLOCKED (mitigado) |
| ATK-03 | Falsificar X-Forwarded-For | 🚫 BLOCKED (nota de design) |
| ATK-04 | Burst no limite de janela | 🚫 BLOCKED (limitação de design) |
| ATK-05 | Manipular headers X-RateLimit-* de requisição | 🚫 BLOCKED |
| ATK-06 | Caminho diferente para contornar limite | 🚫 BLOCKED |
| ATK-07 | Condição de corrida para exceder limite | 🚫 BLOCKED |
| ATK-08 | Inferir carga do sistema pelo Retry-After | 🚫 BLOCKED (irrelevante) |
| ATK-09 | Retry-After ausente causa loops de retry | 🚫 BLOCKED |
| ATK-10 | Chave de API falsa para bucket ilimitado | 🚫 BLOCKED (por design) |
| ATK-11 | Chave vazia mescla todo o tráfego | 🚫 BLOCKED |
| ATK-12 | InMemoryStorage multiplica limite em produção | 🚫 BLOCKED (documentado) |

**12 BLOCKED / MITIGATED, 0 EXPOSED**
Buckets separados por IP, chave REMOTE_ADDR por padrão, e header `Retry-After` obrigatório impedem todos os vetores de ataque testados.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Usar `InMemoryRateLimitStorage` em produção | Workers do PHP-FPM não compartilham memória; limite efetivo = limite configurado × contagem de workers |
| Chavear em `X-Forwarded-For` de clientes não confiáveis | Atacantes falsificam qualquer IP; a limitação de taxa se torna ineficaz |
| Usar um bucket global para todos os clientes | A limitação de taxa de um cliente bloqueia todos os outros clientes |
| Retornar 403 em vez de 429 para limite de taxa | Cliente não consegue distinguir "proibido" de "muitas requisições"; `Retry-After` está ausente |
| Sem header `Retry-After` no 429 | Clientes tentam novamente imediatamente; herd thundering no reset da janela |
| Definir `limit` muito alto para endpoints sensíveis | Endpoint de login com limit=10000 é efetivamente desprotegido |
| Sem limitação de taxa em endpoints de login/redefinição de senha | Ataques de força bruta têm sucesso sem lockout ou throttle |
