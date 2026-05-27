# Como Fazer: API de Gerenciamento de Cotas

> **Referência FT**: FT236 (`NENE2-FT/quotalog`) — API de Gerenciamento de Cotas
> **ATK**: FT236 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API de gerenciamento de cotas onde cada par usuário/recurso tem uma política de taxa
configurável (por hora ou diária), o uso é rastreado em uma tabela separada com chave no início da
janela, e um endpoint `consume` impõe o limite com `429 Too Many Requests` quando excedido.
`check` (somente leitura) e `consume` (mutação) são operações separadas.

---

## Rotas

| Método | Caminho                                  | Descrição                                        |
|--------|------------------------------------------|--------------------------------------------------|
| `PUT`  | `/quotas/{userId}/{resource}`            | Criar ou atualizar uma política de cota          |
| `GET`  | `/quotas/{userId}`                       | Listar todas as políticas de cota de um usuário  |
| `GET`  | `/quotas/{userId}/{resource}`            | Verificar status atual da cota (somente leitura) |
| `POST` | `/quotas/{userId}/{resource}/consume`    | Consumir uma unidade (retorna 429 se excedido)   |
| `POST` | `/quotas/{userId}/{resource}/reset`      | Resetar o uso para zero na janela atual          |

---

## QuotaWindow: calculando o início da janela

`QuotaWindow` é um enum backed com um método `windowStart()` que arredonda para baixo o timestamp
atual para o limite da janela:

```php
enum QuotaWindow: string
{
    case Hourly = 'hourly';
    case Daily  = 'daily';

    public function windowStart(string $now): string
    {
        $dt = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s'),
            self::Daily  => $dt->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
        };
    }
}
```

`setTime(H, 0, 0)` arredonda para a hora atual; `setTime(0, 0, 0)` arredonda para meia-noite UTC.
O resultado é armazenado como a chave `window_start` na tabela de uso — todas as requisições dentro
da mesma janela compartilham o mesmo valor `window_start`.

---

## Design de duas tabelas: políticas e uso

```sql
-- Política de cota: máximo permitido por janela
CREATE TABLE IF NOT EXISTS quota_policies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     TEXT    NOT NULL,
    resource    TEXT    NOT NULL,
    window      TEXT    NOT NULL DEFAULT 'hourly',
    limit_count INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    UNIQUE(user_id, resource)
);

-- Rastreamento de uso: contagem real por janela
CREATE TABLE IF NOT EXISTS quota_usage (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      TEXT    NOT NULL,
    resource     TEXT    NOT NULL,
    window_start TEXT    NOT NULL,
    usage        INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    UNIQUE(user_id, resource, window_start)
);
```

Separar políticas do uso significa que:
- Políticas persistem entre janelas — não é necessário recriá-las a cada período.
- As linhas de uso são automaticamente particionadas por `window_start`. Janelas antigas acumulam
  na tabela; um job em background pode removê-las.
- `UNIQUE(user_id, resource)` nas políticas impede configurações duplicadas.
- `UNIQUE(user_id, resource, window_start)` no uso garante um contador por janela.

---

## check vs consume

`check` é somente leitura — calcula o restante sem qualquer mutação:

```php
public function check(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);
    $remaining   = max(0, $policy->limitCount - $usage);

    return new QuotaStatus(..., remaining: $remaining, allowed: $remaining > 0);
}
```

`consume` verifica o limite primeiro, e só incrementa se permitido:

```php
public function consume(string $userId, string $resource, string $now): ?QuotaStatus
{
    $policy      = $this->findPolicy($userId, $resource);
    $windowStart = $policy->window->windowStart($now);
    $usage       = $this->getUsage($userId, $resource, $windowStart);

    if ($usage >= $policy->limitCount) {
        // Cota excedida — retornar status com allowed=false, NÃO incrementar
        return new QuotaStatus(..., remaining: 0, allowed: false);
    }

    $this->incrementUsage($userId, $resource, $windowStart, $now);
    $newUsage  = $usage + 1;
    $remaining = max(0, $policy->limitCount - $newUsage);

    return new QuotaStatus(..., remaining: $remaining, allowed: true);
}
```

O controlador mapeia `allowed=false` para `429 Too Many Requests`:

```php
$httpStatus = $status->allowed ? 200 : 429;
return $this->json->create($status->toArray(), $httpStatus);
```

`429` é semanticamente correto para esgotamento de cota. Inclua um header `Retry-After` em
produção apontando para o tempo de reset da janela.

---

## Incremento de uso: SELECT-then-INSERT/UPDATE

O incremento de uso é um upsert no nível de aplicação:

```php
private function incrementUsage(string $userId, string $resource, string $windowStart, string $now): void
{
    $existing = $this->executor->fetchAll(
        'SELECT id FROM quota_usage WHERE user_id = ? AND resource = ? AND window_start = ?',
        [$userId, $resource, $windowStart],
    );

    if ($existing !== []) {
        $this->executor->execute(
            'UPDATE quota_usage SET usage = usage + 1, updated_at = ? WHERE user_id = ? AND resource = ? AND window_start = ?',
            [$now, $userId, $resource, $windowStart],
        );
    } else {
        $this->executor->execute(
            'INSERT INTO quota_usage (user_id, resource, window_start, usage, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$userId, $resource, $windowStart, $now, $now],
        );
    }
}
```

`usage = usage + 1` é um incremento atômico no nível do banco — sem leitura-modificação-escrita
no código da aplicação. A restrição `UNIQUE` em `(user_id, resource, window_start)` impede uma
condição de corrida entre dois inserts de primeiro uso concorrentes.

---

## Upsert de política via `PUT`

`PUT /quotas/{userId}/{resource}` é idempotente — cria ou atualiza:

```php
$window     = QuotaWindow::tryFrom($windowRaw);
$limitCount = isset($body['limit_count']) && is_int($body['limit_count']) ? $body['limit_count'] : -1;

$errors = [];
if ($window === null) {
    $errors[] = ['field' => 'window', 'code' => 'invalid', 'message' => 'window must be one of: hourly, daily.'];
}
if ($limitCount < 1) {
    $errors[] = ['field' => 'limit_count', 'code' => 'invalid', 'message' => 'limit_count must be a positive integer.'];
}
```

A verificação estrita `is_int()` rejeita floats e strings JSON. `limitCount < 1` requer
pelo menos 1 — valores zero e negativos são rejeitados.

---

## ATK — Teste de ataque com mentalidade de cracker (FT236)

### ATK-01 — Sem autenticação

**Ataque**: Criar uma política de cota ou consumir em nome de qualquer usuário sem credenciais.

```bash
curl -s -X PUT http://localhost:8080/quotas/user-123/api-calls \
  -H 'Content-Type: application/json' \
  -d '{"window":"daily","limit_count":10}'
```

**Observado**: `200 OK` — nenhum token necessário. Qualquer um pode definir ou esgotar a cota de qualquer usuário.

**Veredicto**: **EXPOSED** (por design para demo FT236). Adicione autenticação; controle o gerenciamento de políticas com uma função admin, e o consume com o token do usuário proprietário.

---

### ATK-02 — Injeção SQL via parâmetro de caminho `{resource}`

**Ataque**: Incorporar metacaracteres SQL no nome do recurso.

```
PUT /quotas/user-1/api'; DROP TABLE quota_policies; --
POST /quotas/user-1/" OR "1"="1/consume
```

**Observado**: A string de recurso é passada diretamente como valor `?` parametrizado em
todas as queries — sem interpolação de string. O SQL injetado é armazenado/comparado como uma
string literal, não executado.

**Veredicto**: **BLOCKED** — queries parametrizadas impedem injeção via parâmetros de caminho.

---

### ATK-03 — `limit_count` negativo ou zero

**Ataque**: Definir um limite de 0 ou -1 para desabilitar o acesso de outro usuário.

```json
{"window": "daily", "limit_count": 0}
{"window": "daily", "limit_count": -999}
```

**Observado**: A verificação `$limitCount < 1` dispara → `422 Unprocessable Entity` com um
erro estruturado para `limit_count`.

**Veredicto**: **BLOCKED** — mínimo de `limit_count` de 1 imposto na camada de aplicação.

---

### ATK-04 — Valor de `window` inválido

**Ataque**: Enviar uma string de janela não suportada.

```json
{"window": "weekly", "limit_count": 100}
{"window": "minutely", "limit_count": 100}
```

**Observado**: `QuotaWindow::tryFrom('weekly')` retorna `null` → `422` com erro estruturado
para `window`.

**Veredicto**: **BLOCKED** — enum backed `tryFrom()` rejeita valores de janela desconhecidos.

---

### ATK-05 — Consumir sem política

**Ataque**: Chamar `POST .../consume` para um usuário/recurso sem política configurada.

```bash
curl -s -X POST http://localhost:8080/quotas/user-ghost/api-calls/consume
```

**Observado**: `findPolicy()` retorna `null` → `404 Not Found` com uma resposta Problem Details.

**Veredicto**: **BLOCKED** — sem política → sem consume. O chamador deve configurar uma política antes de consumir.

---

### ATK-06 — `limit_count` float

**Ataque**: Enviar um float em vez de um inteiro.

```json
{"window": "daily", "limit_count": 9.9}
```

**Observado**: `is_int(9.9)` = `false` no PHP — o valor float decodificado do JSON
(`float` type) falha na verificação. `$limitCount` padrão para `-1` → a guarda `< 1`
dispara → `422`.

**Veredicto**: **BLOCKED** — verificação de tipo estrita `is_int()` rejeita floats JSON.

---

### ATK-07 — `limit_count` extremamente grande

**Ataque**: Definir um limit_count de `PHP_INT_MAX` ou `9999999999`.

```json
{"window": "daily", "limit_count": 9223372036854775807}
```

**Observado**: `is_int()` passa (PHP representa como `int`); a verificação `< 1` passa.
O valor é armazenado e usado em comparações sem problema. Não existe limite superior.

**Veredicto**: **EXPOSED** — sem `limit_count` máximo imposto. Um limite muito grande é
efetivamente o mesmo que "sem limite". Adicione:
```php
if ($limitCount > 1_000_000) {
    $errors[] = ['field' => 'limit_count', 'code' => 'too_large', 'message' => 'limit_count must not exceed 1 000 000.'];
}
```

---

### ATK-08 — Condição de corrida no consume concorrente no limite

**Ataque**: Enviar duas requisições `POST .../consume` simultâneas quando `usage == limit - 1`.

**Observado**: Ambas as requisições leem `usage = limit - 1` antes que qualquer incremento seja executado.
Ambas veem `usage < limitCount` → ambas chamam `incrementUsage()`. Ambas têm sucesso — o uso termina
em `limit + 1`, ambas as respostas retornam `allowed: true`.

**Veredicto**: **EXPOSED** — o padrão de verificar-depois-incrementar não é atômico. Corrija com uma transação:
```sql
BEGIN;
SELECT usage FROM quota_usage WHERE ... FOR UPDATE;
-- verificar < limit
UPDATE quota_usage SET usage = usage + 1 WHERE ...;
COMMIT;
```
Ou use `UPDATE ... SET usage = CASE WHEN usage < ? THEN usage + 1 ELSE usage END RETURNING usage` no PostgreSQL.

---

### ATK-09 — Nome `{resource}` desconhecido ou arbitrário

**Ataque**: Usar um nome de recurso que nunca foi pretendido.

```
PUT /quotas/user-1/../../../../etc/passwd
PUT /quotas/user-1/system::admin
POST /quotas/user-1/; DROP TABLE quota_usage;--/consume
```

**Observado**: Path traversal (`../`) é decodificado por URL antes do roteamento; o roteador vê
como caminhos de múltiplos segmentos e não corresponde à rota `{resource}`. Caracteres especiais
são armazenados como strings literais via queries parametrizadas (ver ATK-02).

**Veredicto**: **BLOCKED** na prática — o roteador rejeita path traversal, o SQL é seguro.
Considere adicionar uma allowlist de nomes de recurso ou verificação de formato se os nomes de
recurso devem ser restritos a valores conhecidos.

---

### ATK-10 — Resetar a cota de outro usuário

**Ataque**: Resetar o contador de cota de um usuário diferente para contornar seu throttling.

```bash
curl -s -X POST http://localhost:8080/quotas/target-user/api-calls/reset
```

**Observado**: `200 OK` — sem verificação de propriedade. Qualquer chamador pode resetar o uso
de cota de qualquer usuário, habilitando imediatamente o acesso deles novamente.

**Veredicto**: **EXPOSED** — mesma raiz do ATK-01. Controle `reset` com uma função admin.

---

### ATK-11 — Comprimento ilimitado de `{userId}` e `{resource}`

**Ataque**: Enviar valores de segmento de caminho extremamente longos.

```
PUT /quotas/<10000 chars>/<5000 chars>
```

**Observado**: Strings longas são aceitas e armazenadas em colunas `TEXT` sem limite.
O desempenho do índice em chaves muito longas degrada.

**Veredicto**: **EXPOSED** — adicione uma guarda de comprimento:
```php
if (strlen($userId) > 255 || strlen($resource) > 255) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, ...);
}
```

---

### ATK-12 — Manipulação de `window_start` via desvio de relógio

**Ataque**: Se o chamador puder influenciar `$now`, ele pode deslocar o início da janela para
artificialmente estender ou reiniciar uma janela.

**Observado**: `$now` é calculado dentro do controlador via `new \DateTimeImmutable()` —
não é fornecido pelo usuário. O chamador não pode influenciar o cálculo da janela.

**Veredicto**: **BLOCKED** — o relógio do servidor é a única fonte de tempo. Para sistemas
distribuídos com múltiplos nós, garanta que todos os nós usem UTC e sejam sincronizados por NTP.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| ATK-01 | Sem autenticação | EXPOSED |
| ATK-02 | Injeção SQL via parâmetro de caminho resource | BLOCKED |
| ATK-03 | limit_count negativo/zero | BLOCKED |
| ATK-04 | Valor de window inválido | BLOCKED |
| ATK-05 | Consumir sem política | BLOCKED |
| ATK-06 | limit_count float | BLOCKED |
| ATK-07 | limit_count extremamente grande | EXPOSED |
| ATK-08 | Condição de corrida no consume concorrente | EXPOSED |
| ATK-09 | Nome de recurso arbitrário | BLOCKED |
| ATK-10 | Resetar cota de outro usuário | EXPOSED |
| ATK-11 | Comprimento ilimitado de userId/resource | EXPOSED |
| ATK-12 | Manipulação do início da janela | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-01 / ATK-10** — Adicionar autenticação e autorização
2. **ATK-08** — Envolver consume em uma transação (verificar-incrementar atomicamente)
3. **ATK-07** — Adicionar limite superior para `limit_count`
4. **ATK-11** — Adicionar limites de comprimento nos valores de parâmetros de caminho

---

## Howtos relacionados

- [`rate-limiting.md`](rate-limiting.md) — limitação de taxa em nível de middleware
- [`sliding-window-rate-limiter.md`](sliding-window-rate-limiter.md) — contador de janela deslizante
- [`api-usage-metering.md`](api-usage-metering.md) — rastreamento de uso por chave de API
- [`credit-ledger.md`](credit-ledger.md) — modelo de crédito/débito para sistemas similares a cotas
