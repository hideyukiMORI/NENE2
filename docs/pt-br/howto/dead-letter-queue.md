# Como Fazer: Dead Letter Queue (DLQ)

> **Referência FT**: FT72 (`NENE2-FT/deadletterlog`) — API de Dead Letter Queue

Demonstra uma fila de mensagens confiável com retentativas de backoff exponencial e uma
dead letter queue. Mensagens com falha são reagendadas automaticamente com delays crescentes;
após esgotar todas as retentativas, elas passam para o estado `dead` onde podem ser inspecionadas
e reexecutadas. Suporta múltiplas filas nomeadas via parâmetro de caminho.

---

## Ciclo de vida da mensagem

```
enqueue ──▶ pending ──claim──▶ processing
                                    │
                        ┌──succeed──┤──fail (retries left)──▶ pending (retry_after)
                        │           │
                        ▼           └──fail (exhausted)──▶ dead ──replay──▶ pending
                    succeeded
```

| Status | Descrição |
|--------|-----------|
| `pending` | Pronto para ser reivindicado (ou aguardando até `retry_after`) |
| `processing` | Reivindicado por um worker, sendo processado |
| `succeeded` | Concluído com sucesso |
| `dead` | Esgotou todas as retentativas — na dead letter queue |

---

## Rotas

| Método | Caminho                                       | Descrição                           |
|--------|-----------------------------------------------|-------------------------------------|
| `POST` | `/queues/{queue}/messages`                    | Enfileirar uma mensagem             |
| `GET`  | `/queues/{queue}/messages`                    | Listar mensagens em uma fila        |
| `GET`  | `/queues/{queue}/messages/{id}`               | Obter uma única mensagem            |
| `POST` | `/queues/{queue}/claim`                       | Reivindicar a próxima mensagem pendente |
| `POST` | `/queues/{queue}/messages/{id}/succeed`       | Marcar como bem-sucedida            |
| `POST` | `/queues/{queue}/messages/{id}/fail`          | Marcar como falha (retry ou DLQ)    |
| `POST` | `/queues/{queue}/messages/{id}/replay`        | Reexecutar uma mensagem morta       |

---

## Enfileirar uma mensagem

```php
// POST /queues/emails/messages
$body = [
    'payload'     => '{"to":"alice@example.com","subject":"Welcome"}',  // string obrigatória
    'max_retries' => 5,  // opcional, padrão 3, faixa 1–10
];
```

`max_retries` é validado para ser entre 1 e 10:

```php
$maxRetries = isset($body['max_retries']) && is_int($body['max_retries']) ? $body['max_retries'] : 3;

if ($maxRetries < 1 || $maxRetries > 10) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'max_retries', 'code' => 'invalid', 'message' => 'max_retries must be between 1 and 10.']],
    ]);
}
```

---

## Reivindicar a próxima mensagem pendente

Um worker chama `POST /queues/{queue}/claim` para desenfileirar uma mensagem atomicamente:

```php
public function claim(string $queue, string $now): ?Message
{
    $rows = $this->executor->fetchAll(
        "SELECT * FROM messages
         WHERE queue = ? AND status = 'pending'
           AND (retry_after IS NULL OR retry_after <= ?)
         ORDER BY created_at ASC LIMIT 1",
        [$queue, $now],
    );

    if ($rows === []) {
        return null;  // nenhuma mensagem disponível
    }

    $id = (int) $rows[0]['id'];
    $this->executor->execute(
        "UPDATE messages SET status = 'processing', updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_after <= now` filtra mensagens que estão aguardando entre retentativas. Mensagens
são reivindicadas em ordem FIFO (`ORDER BY created_at ASC`).

> **Nota de atomicidade**: Sem uma transação, dois workers concorrentes podem reivindicar a mesma
> mensagem se ambos lerem a mesma linha antes de qualquer UPDATE ser executado. Encapsule o SELECT +
> UPDATE em uma transação com `SELECT ... FOR UPDATE` (MySQL/PostgreSQL) ou use
> `UPDATE ... WHERE status = 'pending' RETURNING id` para reivindicação verdadeiramente atômica.

---

## Tratamento de falhas com backoff exponencial

Quando um worker reporta falha (`POST .../fail`), o repositório agenda uma retentativa
ou promove a mensagem para a dead letter queue:

```php
public function fail(int $id, string $error, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Processing) {
        return null;
    }

    $newRetryCount = $msg->retryCount + 1;

    if ($newRetryCount >= $msg->maxRetries) {
        // Esgotado — mover para DLQ
        $this->executor->execute(
            "UPDATE messages SET status = 'dead', retry_count = ?, last_error = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $now, $id],
        );
    } else {
        // Agendar retentativa com backoff exponencial
        $backoffSeconds = min(2 ** $newRetryCount, 3600);
        $retryAfter     = (new \DateTimeImmutable($now))
            ->modify("+{$backoffSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $this->executor->execute(
            "UPDATE messages SET status = 'pending', retry_count = ?, last_error = ?,
             retry_after = ?, updated_at = ? WHERE id = ?",
            [$newRetryCount, $error, $retryAfter, $now, $id],
        );
    }

    return $this->findById($id);
}
```

### Agenda de backoff (max_retries = 5)

| Tentativa | Segundos de backoff | Fórmula |
|-----------|---------------------|---------|
| 1ª falha | 2 s | 2^1 |
| 2ª falha | 4 s | 2^2 |
| 3ª falha | 8 s | 2^3 |
| 4ª falha | 16 s | 2^4 |
| 5ª falha | → dead | retentativas esgotadas |

`min(2 ** $newRetryCount, 3600)` limita o backoff máximo em 1 hora. Para grandes contagens
de retry, isso previne delays de vários dias enquanto ainda dá ao serviço tempo para se recuperar.

---

## Reexecutar mensagens mortas

Uma mensagem morta pode ser reexecutada redefinindo-a para `pending` com estado de retry limpo:

```php
public function replay(int $id, string $now): ?Message
{
    $msg = $this->findById($id);
    if ($msg === null || $msg->status !== MessageStatus::Dead) {
        return null;  // 409 Conflict
    }

    $this->executor->execute(
        "UPDATE messages SET status = 'pending', retry_count = 0,
         last_error = NULL, retry_after = NULL, updated_at = ? WHERE id = ?",
        [$now, $id],
    );

    return $this->findById($id);
}
```

`retry_count` reseta para 0 para que a mensagem obtenha o orçamento completo de `max_retries` novamente.
O valor original de `max_retries` é preservado.

> **Boa prática**: antes de reexecutar, corrija a causa subjacente da falha. Reexecutar
> em um sistema quebrado apenas vai repopular a DLQ.

---

## Múltiplas filas nomeadas

O parâmetro de caminho `{queue}` roteia mensagens por nome. Qualquer string não vazia é válida:

```
POST /queues/emails/messages
POST /queues/notifications/messages
POST /queues/webhooks/messages
```

Todas as consultas filtram por `queue = ?`, então cada fila é isolada. Nenhuma etapa de
registro de fila é necessária — as filas são criadas implicitamente no primeiro enfileiramento.

---

## Schema

```sql
CREATE TABLE messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    queue       TEXT    NOT NULL DEFAULT 'default',
    payload     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    retry_after TEXT,           -- NULL quando não agendado para retry
    last_error  TEXT,           -- NULL até a primeira falha
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

Escolhas de design principais:
- `payload` é uma string opaca — a fila não inspeciona nem valida o conteúdo da mensagem.
- `last_error` armazena a mensagem de falha mais recente para depuração.
- `retry_after` é `NULL` para novas mensagens e limpo no replay, permitindo que `retry_after <= now` funcione sem tratamento especial.

---

## Padrão de worker

Um worker faz polling e processa uma mensagem por vez:

```php
// Loop do worker (pseudocódigo)
while (true) {
    $msg = claim('/queues/emails/messages');
    if ($msg === null) {
        sleep(5);  // sem mensagens, aguardar
        continue;
    }

    try {
        sendEmail(json_decode($msg->payload));
        succeed($msg->id);
    } catch (Exception $e) {
        fail($msg->id, $e->getMessage());
    }
}
```

Mantenha os ciclos claim-to-succeed/fail curtos. O processamento de longa duração sem timeouts
deixa mensagens no estado `processing` para sempre se o worker travar. Adicione uma
coluna `processing_timeout` e um job reaper para reclamar mensagens com timeout.

---

## Howtos relacionados

- [`job-queue.md`](job-queue.md) — fila de jobs básica sem DLQ
- [`notification-queue.md`](notification-queue.md) — padrões de fila de notificações
- [`idempotency.md`](idempotency.md) — processamento idempotente para entrega at-least-once
- [`webhook-delivery.md`](webhook-delivery.md) — padrões de retry de webhook
