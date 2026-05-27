# Como Fazer: Fila de Jobs em Background com Retry e Idempotência

> **Referência FT**: FT255 (`NENE2-FT/queuelog`) — Fila de Jobs em Background com Retry e Idempotência
> **VULN**: FT255 — avaliação de vulnerabilidades (V-01 a V-10)

Demonstra uma fila de jobs persistente com SQLite. Jobs têm níveis de prioridade,
passam por uma máquina de estado `pending → running → completed|failed` e suportam
retry automático em caso de falha com limite de retry configurável. Um idempotency key
previne criação duplicada de jobs. Inclui uma avaliação completa de vulnerabilidades.

---

## Rotas

| Método | Caminho                    | Descrição                               |
|--------|-------------------------|-------------------------------------------|
| `POST` | `/jobs`                 | Enfileirar um job (idempotency key opcional)  |
| `GET`  | `/jobs`                 | Listar jobs (filtrável por status)          |
| `GET`  | `/jobs/{id}`            | Obter um único job                          |
| `POST` | `/jobs/claim`           | Worker reivindica o próximo job pendente        |
| `POST` | `/jobs/{id}/complete`   | Worker marca job como completo              |
| `POST` | `/jobs/{id}/fail`       | Worker marca job como falho (com retry)    |

> **Ordem das rotas**: `/jobs/claim` deve ser registrado antes de `/jobs/{id}` para que o segmento literal
> `claim` não seja capturado como parâmetro de caminho.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    type            TEXT    NOT NULL,
    payload         TEXT    NOT NULL DEFAULT '{}',
    priority        INTEGER NOT NULL DEFAULT 0,
    status          TEXT    NOT NULL DEFAULT 'pending',
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    idempotency_key TEXT    UNIQUE,
    claimed_at      TEXT,
    worker_id       TEXT,
    error           TEXT,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);
```

`idempotency_key TEXT UNIQUE` reforça unicidade no nível do BD. `claimed_at`,
`worker_id` e `error` são nullable — definidos apenas quando um job entra em `running` ou `failed`.

---

## Prioridade: enum numérico para ordenação SQL

```php
enum JobPriority: int
{
    case Low      = 0;
    case Medium   = 10;
    case High     = 20;
    case Critical = 30;

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'low' => self::Low, 'medium' => self::Medium,
            'high' => self::High, 'critical' => self::Critical,
            default => throw new \InvalidArgumentException("Unknown priority: {$label}"),
        };
    }
}
```

Valores numéricos permitem ordenação direta por `ORDER BY priority DESC`. Um enum de string exigiria
uma expressão `CASE` ou uma tabela de lookup de prioridade. Espaços entre valores (0, 10, 20, 30) permitem
inserir níveis de prioridade futuros sem renumeração.

---

## Claim: FIFO de maior prioridade

```php
public function claim(string $workerId, string $now): ?Job
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM jobs WHERE status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1",
        [],
    );
    if ($rows === []) {
        return null;
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE jobs SET status = 'running', claimed_at = ?, worker_id = ?, updated_at = ? WHERE id = ?",
        [$now, $workerId, $now, $id],
    );

    return $this->findById($id);
}
```

`ORDER BY priority DESC, created_at ASC` seleciona o job de maior prioridade e, entre
jobs de igual prioridade, o mais antigo (FIFO). `LIMIT 1` garante que apenas um job seja selecionado.

Este claim é **não-atômico** (veja V-06). Para configuração de worker único, isso é aceitável.
Para workers concorrentes, use `BEGIN IMMEDIATE` do SQLite + `SELECT … LIMIT 1 FOR UPDATE`
(MySQL) ou um UPDATE condicional `status = 'pending' AND id = ?` com verificação de `changes()`.

---

## Lógica de retry: reenfileirar vs. falhar

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        // Reenfileirar: resetar para pending com retry_count incrementado
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        // Esgotado: falha permanente
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

`retry_count < max_retries` verifica se o job tem retries restantes. Se sim,
o job retorna para `pending` (com `claimed_at`/`worker_id` limpos) e pode ser reivindicado novamente.
Se esgotado, transita para o estado terminal `failed`.

Ao reenfileirar, `claimed_at = NULL` e `worker_id = NULL` são limpos para que o job apareça
como um job pendente novo para o próximo worker que o reivindicar.

---

## Idempotency key: deduplicação na criação

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}

$job = $this->repo->create($type, ..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

Se um job com o mesmo `idempotency_key` já existe, o job existente é retornado
com `200 OK` em vez de criar um duplicado. Um novo job retorna `201 Created`.
A constraint `UNIQUE` em `idempotency_key` fornece uma guarda de segundo nível contra
condições de corrida.

---

## Máquina de estado

```
pending ──(claim)──→ running ──(complete)──→ completed (terminal)
                        │
                        └──(fail, retries disponíveis)──→ pending
                        │
                        └──(fail, retries esgotados)──→ failed (terminal)
```

`complete()` e `fail()` ambos verificam `status = Running` antes de aplicar a transição.
Um retorno `null` de qualquer um indica que o job não foi encontrado ou não estava no estado correto,
mapeado para `409 Conflict` pelo controller.

---

## VULN — Avaliação de vulnerabilidades (FT255)

### V-01 — Sem autenticação: qualquer chamador pode enfileirar, reivindicar ou completar qualquer job

**Risco**: Todos os endpoints não são autenticados.

**Impacto**: Um atacante pode enfileirar jobs arbitrários com qualquer tipo e payload, reivindicar
jobs legítimos para impedir que workers reais os processem, e marcar jobs como completos
ou falhos sem executar o trabalho real.

**Veredicto**: **EXPOSED** — adicionar autenticação. Endpoints de worker (`/jobs/claim`,
`/jobs/{id}/complete`, `/jobs/{id}/fail`) devem exigir uma API key de worker ou JWT.
Enfileiramento deve ser restrito a produtores autenticados.

---

### V-02 — Tipo de job é qualquer string: nenhuma allowlist reforçada

**Risco**: `type` aceita qualquer string não-vazia. Um atacante pode enfileirar jobs de tipos
que o sistema não trata (ex.: `"DROP TABLE"`, `"shutdown"`, `"admin_task"`).

**Impacto**: Se o worker despacha baseado em `type` (ex.: `match($job->type) { ... }`),
tipos desconhecidos são silenciosamente pulados ou acionam handlers padrão inesperados.

**Veredicto**: **EXPOSED** — validar `type` contra uma allowlist de tipos de job conhecidos.
Retornar `422` para tipos desconhecidos. Exemplo:

```php
if (!in_array($type, ['email', 'pdf', 'sync'], true)) {
    return $this->problems->create($request, 'validation-failed', '...', 422, ...);
}
```

---

### V-03 — Manipulação de prioridade: atacante define prioridade `critical`

**Ataque**: Enfileirar um job com `"priority": "critical"` para preemptar todos os jobs existentes.

```json
{"type": "spam", "payload": {}, "priority": "critical"}
```

**Observado**: A requisição tem sucesso com `201`. O job de spam agora está na frente da
fila e é reivindicado antes de quaisquer jobs legítimos de alta prioridade.

**Veredicto**: **EXPOSED** — restringir quem pode definir níveis de alta prioridade. Produtores sem
confiança elevada devem ser limitados a `low` ou `medium`. Rejeitar `critical` de
chamadores não autenticados.

---

### V-04 — Falsificação de worker ID: qualquer pessoa pode reivindicar com qualquer worker_id

**Ataque**: Enviar um claim com `"worker_id": "legitimate-worker-1"`.

**Observado**: O claim tem sucesso — o job é atribuído ao worker ID falsificado.
O worker legítimo não consegue distinguir isso de seus próprios claims.

**Veredicto**: **EXPOSED** — `worker_id` deve ser derivado de uma identidade autenticada
(API key → nome do worker), não fornecido pelo chamador. Nunca confie em worker IDs fornecidos pelo chamador.

---

### V-05 — Tomada de estado do job: qualquer chamador pode completar/falhar qualquer job em execução

**Ataque**: Completar ou falhar um job que um worker diferente reivindicou.

```bash
# Worker A reivindica job 1; atacante o completa antes de Worker A terminar:
POST /jobs/1/complete
```

**Observado**: `complete()` verifica apenas `status = Running`. Nenhuma verificação de propriedade verifica
que o chamador é o worker que reivindicou o job.

**Veredicto**: **EXPOSED** — adicionar condição `WHERE worker_id = $requestWorkerId` a
`complete()` e `fail()`. Retornar `409` se o worker não possuir o job.

---

### V-06 — Condição de corrida no claim: SELECT + UPDATE não atômico

**Risco**: `claim()` realiza `SELECT … LIMIT 1` depois `UPDATE … WHERE id = ?`. Dois
workers concorrentes poderiam selecionar o mesmo job antes que qualquer um o atualize.

**Ataque**: Dois workers ambos veem job 1 como `pending`, ambos o atualizam para `running`,
ambos executam o job. A segunda atualização vence a coluna `worker_id`, mas o job
executa duas vezes.

**Veredicto**: **EXPOSED** — usar um padrão de claim atômico:
```sql
UPDATE jobs SET status='running', worker_id=?, claimed_at=?
WHERE id = (SELECT id FROM jobs WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT 1)
  AND status = 'pending'
```
Depois verificar `changes() = 1`. No SQLite, envolver em `BEGIN IMMEDIATE` previne
leituras concorrentes de ver a mesma linha pendente.

---

### V-07 — Tamanho do payload: sem limite no payload do job

**Risco**: `payload` aceita qualquer objeto JSON sem validação de tamanho.

**Impacto**: Um payload de vários megabytes consome armazenamento e memória quando o job é
buscado por workers ou listado na fila.

**Veredicto**: **EXPOSED** — adicionar verificação de tamanho do payload (ex.: `strlen($json) > 65536 → 422`).
Confiar no middleware de tamanho de requisição como limite externo.

---

### V-08 — SQL injection via type ou payload

**Ataque**: Inserir metacaracteres SQL nos campos `type` ou `payload`.

```json
{"type": "'; DROP TABLE jobs; --", "payload": {}}
```

**Observado**: Valores são vinculados como placeholders parametrizados `?`. A injeção é
armazenada como texto literal no banco de dados; o SQL nunca é executado.

**Veredicto**: **BLOCKED** — queries parametrizadas previnem SQL injection.

---

### V-09 — Colisão de idempotency key: atacante adivinha uma chave legítima

**Ataque**: Adivinhar ou enumerar a idempotency key de um chamador legítimo e enviar o
mesmo job com um payload diferente.

**Observado**: O job existente é retornado sem alteração. A requisição do atacante NÃO
cria um novo job — a constraint `UNIQUE` e a verificação no nível da aplicação ambas o previnem.
O atacante descobre que o job existe (via o `200` retornado) mas não pode modificá-lo.

**Veredicto**: **PARCIALMENTE BLOCKED** — criação duplicada está bloqueada. No entanto, o
atacante pode enumerar a existência de jobs sondando idempotency keys. Use chaves aleatórias longas
(ex.: UUID v4) para tornar a enumeração inviável. A resposta a uma chave correspondente revela
que o job existe e seu status.

---

### V-10 — Divulgação de mensagem de erro em jobs falhos

**Risco**: Mensagens de erro de worker de `POST /jobs/{id}/fail` são armazenadas na coluna `error`
e retornadas em todas as respostas de listagem/obtenção.

**Impacto**: Mensagens de erro internas (stack traces, strings de conexão ao BD, caminhos de arquivo internos)
enviadas por workers são visíveis para qualquer chamador de `GET /jobs`.

**Veredicto**: **EXPOSED** — sanitizar mensagens de erro antes de armazenar (remover detalhes sensíveis).
Limitar visibilidade do campo `error` para funções de admin nas respostas de listagem/obtenção.

---

## Resumo VULN

| # | Vulnerabilidade | Veredicto |
|---|---------------|---------|
| V-01 | Sem autenticação em nenhum endpoint | EXPOSED |
| V-02 | Tipo de job: sem allowlist | EXPOSED |
| V-03 | Manipulação de prioridade (jobs critical) | EXPOSED |
| V-04 | Falsificação de worker ID | EXPOSED |
| V-05 | Tomada de estado do job (sem verificação de propriedade) | EXPOSED |
| V-06 | Condição de corrida no claim (não-atômico) | EXPOSED |
| V-07 | Tamanho do payload: sem limite | EXPOSED |
| V-08 | SQL injection via type/payload | BLOCKED |
| V-09 | Colisão/enumeração de idempotency key | PARTIALLY BLOCKED |
| V-10 | Divulgação de mensagem de erro na listagem | EXPOSED |

**Correções críticas antes de produção**:
1. **V-01** — Adicionar autenticação para produtores e workers (níveis de auth separados)
2. **V-02** — Validar `type` contra uma allowlist conhecida
3. **V-03 / V-04 / V-05** — Derivar identidade do worker de sessão autenticada; adicionar verificação de propriedade de `worker_id`
4. **V-06** — Usar claim atômico (`UPDATE … WHERE … AND status='pending'` + `changes() = 1`)
5. **V-10** — Sanitizar mensagens de erro do worker antes do armazenamento; restringir visibilidade

---

## Howtos relacionados

- [`notification-queue.md`](notification-queue.md) — API de fila de notificações (notiflog FT214)
- [`idempotency.md`](idempotency.md) — padrão de idempotency key para requisições POST
- [`dead-letter-queue.md`](dead-letter-queue.md) — fila de dead letter com retry (deadletterlog FT72)
- [`transactions.md`](transactions.md) — envolvendo operações de fila em transações
