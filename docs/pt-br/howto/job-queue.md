# Fila de Jobs em Background com Retry e Idempotência

Este guia cobre a implementação de uma fila de jobs em background persistente em aplicações NENE2. O padrão suporta filas de prioridade, retry automático com contadores de backoff e criação idempotente de jobs.

## Conceitos principais

Uma fila de jobs desacopla o trabalho dos ciclos de requisição HTTP. O handler HTTP enfileira um job e retorna imediatamente; um processo worker separado reivindica e executa os jobs.

Estados principais: `pending` → `running` → `completed` ou `failed` (com reenfileiramento automático quando há retries disponíveis).

## Design do schema

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

`idempotency_key UNIQUE` é reforçado no nível do banco de dados, não apenas no nível da aplicação. Isso previne condições de corrida onde duas requisições HTTP concorrentes ambas passam a verificação de camada de aplicação e ambas tentam INSERT.

## Ciclo de vida do job

```
POST /jobs                  → pending (retry_count=0)
POST /jobs/claim            → running (worker_id, claimed_at definidos)
POST /jobs/{id}/complete    → completed
POST /jobs/{id}/fail        → pending (retry_count+1) se há retries disponíveis
                            → failed se retry_count >= max_retries
```

## Lógica de retry

Quando um worker chama `fail`, o repositório decide se reenfileira ou falha permanentemente:

```php
public function fail(int $id, string $error, string $now): ?Job
{
    $job = $this->findById($id);
    if ($job === null || $job->status !== JobStatus::Running) {
        return null;
    }

    if ($job->retryCount < $job->maxRetries) {
        $this->executor->execute(
            "UPDATE jobs SET status = 'pending', retry_count = retry_count + 1,
             error = ?, claimed_at = NULL, worker_id = NULL, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    } else {
        $this->executor->execute(
            "UPDATE jobs SET status = 'failed', error = ?, updated_at = ? WHERE id = ?",
            [$error, $now, $id],
        );
    }

    return $this->findById($id);
}
```

O campo `error` armazena o motivo da falha **mais recente** mesmo ao reenfileirar, dando aos operadores uma trilha de diagnóstico no registro do job.

## Idempotência

Passe um `idempotency_key` ao criar um job para tornar a operação segura para retry do cliente HTTP:

```http
POST /jobs
Content-Type: application/json

{
  "type": "send-invoice",
  "payload": {"invoice_id": 42},
  "idempotency_key": "invoice-42-send-2026-05"
}
```

- Primeira chamada: `201 Created` — job é criado.
- Chamadas subsequentes com a mesma chave: `200 OK` — job existente retornado, nenhum duplicata criada.

A constraint `UNIQUE` do banco de dados em `idempotency_key` é a rede de segurança. Verifique na camada de aplicação primeiro para evitar depender de tratamento de exceção como caminho de código primário:

```php
if ($idempotencyKey !== null) {
    $existing = $this->repo->findByIdempotencyKey($idempotencyKey);
    if ($existing !== null) {
        return $this->json->create($existing->toArray(), 200);
    }
}
$job = $this->repo->create(..., $idempotencyKey, $maxRetries);
return $this->json->create($job->toArray(), 201);
```

## Fila de prioridade

Jobs são reivindicados por prioridade DESC, depois created_at ASC (FIFO dentro de um nível):

```sql
SELECT * FROM jobs
WHERE status = 'pending'
ORDER BY priority DESC, created_at ASC
LIMIT 1
```

Níveis de prioridade (valores inteiros armazenados, labels legíveis expostos):

| Label    | Valor |
|----------|-------|
| low      | 0     |
| medium   | 10    |
| high     | 20    |
| critical | 30    |

## Padrão de worker

Workers são processos sem estado que fazem loop: reivindicar → executar → completar ou falhar.

```
loop:
  job = POST /jobs/claim { worker_id: "worker-1" }
  if job é null → dormir, continuar

  try:
    executar(job.type, job.payload)
    POST /jobs/{job.id}/complete {}
  catch error:
    POST /jobs/{job.id}/fail { error: error.message }
```

Workers se identificam com `worker_id` para que operadores possam ver qual worker está com um job e diagnosticar workers travados.

## Detecção de job travado

Jobs com status `running` com um timestamp `claimed_at` mais antigo que um threshold estão travados (worker travou). Um processo de manutenção deve detectar e reenfileirá-los:

```sql
UPDATE jobs
SET status = 'pending', retry_count = retry_count + 1,
    claimed_at = NULL, worker_id = NULL, updated_at = ?
WHERE status = 'running'
  AND claimed_at < ?             -- mais antigo que threshold de timeout
  AND retry_count < max_retries
```

## max_retries=0 para jobs não-retryable

Alguns jobs não devem ser retentados (ex.: pagamentos, webhooks externos onde replay causaria dano). Defina `max_retries: 0` ao criar:

```json
{ "type": "charge-card", "max_retries": 0, "idempotency_key": "charge-order-99" }
```

A primeira chamada `fail` imediatamente transita o job para `failed`.

## Decisões de design

**Por que a lógica de retry no repositório, não no worker?** A decisão de reenfileirar é um invariante de camada de dados (retry_count < max_retries), não lógica de negócio. Colocá-la no repositório mantém os workers simples e previne inconsistência de workers que implementam a verificação de forma diferente.

**Por que constraint UNIQUE em idempotency_key no nível do BD?** Verificações no nível da aplicação têm condições de corrida sob requisições concorrentes. A constraint do BD é a guarda autoritativa; a verificação no nível da aplicação é uma otimização para evitar depender do tratamento de exceções.

**Por que armazenar prioridade como inteiro?** Permite adicionar níveis de prioridade intermediários mais tarde sem alterações de schema. O label legível é derivado, não armazenado.
