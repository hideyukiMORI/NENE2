# Como Fazer: API de Lembretes Agendados

> **Referência FT**: FT235 (`NENE2-FT/reminderlog`) — API de Lembretes Agendados

Demonstra uma API de agendamento de lembretes com validação de datetime futuro com consciência de fuso horário,
identificação leve de usuário por requisição via header, prevenção de IDOR através de queries com escopo de
propriedade, e a distinção 404/409 ao cancelar um lembrete.

---

## Rotas

| Método  | Caminho                    | Descrição                                              |
|---------|----------------------------|--------------------------------------------------------|
| `POST`  | `/reminders`               | Criar um lembrete (`remind_at` futuro obrigatório)     |
| `GET`   | `/reminders`               | Listar lembretes do chamador (filtrável por status)    |
| `PATCH` | `/reminders/{id}/cancel`   | Cancelar um lembrete pendente                          |

Todas as rotas exigem o header `X-User-Id`.

---

## Identificação leve de usuário via header

Em vez de JWT Bearer, esta API usa um header inteiro `X-User-Id` como mecanismo mínimo de
autenticação/identificação:

```php
$userId = V::userId($request->getHeaderLine('X-User-Id'));

if ($userId === null) {
    return $this->responseFactory->create(
        ['error' => 'X-User-Id header must be a positive integer.'],
        401,
    );
}
```

`V::userId()` valida o valor do header:

```php
public static function userId(string $header): ?int
{
    // ctype_digit('') === false — string vazia já rejeitada.
    if (!ctype_digit($header) || strlen($header) > 18) {
        return null;
    }

    $id = (int) $header;

    return $id > 0 ? $id : null;
}
```

Propriedades principais:
- `ctype_digit()` — imune a ReDoS, rejeita `0`, `-1`, `1.5`, `abc`, string vazia.
- `strlen > 18` — guarda de overflow antes do cast `(int)` (PHP_INT_MAX tem 19 dígitos).
- `$id > 0` — rejeita o inteiro zero após análise.

Para produção, substitua por validação JWT ou de sessão. O padrão `X-User-Id` é
adequado para serviços internos onde o gateway upstream já autenticou o usuário
e encaminha seu ID.

---

## Validação de datetime futuro (com consciência de fuso horário)

`remind_at` deve ser um datetime ISO 8601 válido com um offset de fuso horário explícito **e**
deve ser estritamente no futuro em relação ao agora:

```php
$now      = (new DateTimeImmutable())->format(DATE_ATOM);
$remindAt = V::futureDatetime($rawRemindAt, $now);

if ($remindAt === null) {
    return $this->responseFactory->create(
        ['error' => 'remind_at must be a valid ISO 8601 datetime with timezone offset and must be in the future.'],
        422,
    );
}
```

`V::futureDatetime()` compõe duas verificações:

```php
public static function futureDatetime(mixed $raw, string $now): ?string
{
    $dt = self::isoDatetime($raw);   // Passo 1: validação de formato + intervalo

    if ($dt === null) {
        return null;
    }

    // Passo 2: verificação de futuro com consciência de fuso horário
    $dtObj  = DateTimeImmutable::createFromFormat(DATE_ATOM, $dt);
    $nowObj = DateTimeImmutable::createFromFormat(DATE_ATOM, $now);

    if ($dtObj === false || $nowObj === false) {
        return null;
    }

    return $dtObj > $nowObj ? $dt : null;  // Comparação de objetos normaliza para UTC
}
```

`V::isoDatetime()` realiza a verificação de formato primeiro:

```php
public static function isoDatetime(mixed $raw): ?string
{
    // Regex estrito: requer offset ±HH:MM — rejeita 'Z', somente data, offset ausente.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-])(\d{2}):(\d{2})$/', $raw, $m)) {
        return null;
    }

    // Validar intervalo do offset de fuso horário: offsets UTC válidos são −14:00 … +14:00.
    $tzHours   = (int) $m[2];
    $tzMinutes = (int) $m[3];

    if ($tzHours > 14 || $tzMinutes > 59 || ($tzHours === 14 && $tzMinutes > 0)) {
        return null;
    }
    // ... validação de round-trip para datas com overflow (30 de fev etc.)
}
```

A comparação de objetos `DateTimeImmutable` (`>`) converte ambos os lados para UTC antes
de comparar — então `2026-06-01T09:00:00+09:00` (00:00 UTC) é corretamente comparado a
`2026-06-01T01:00:00+01:00` (00:00 UTC) como igual.

---

## Prevenção de IDOR: busca com escopo de propriedade

Todas as operações que tocam um lembrete específico usam `WHERE id = ? AND user_id = ?`:

```php
public function findForUser(int $id, int $userId): ?Reminder
{
    $stmt = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $this->hydrate($row) : null;
}
```

Se o lembrete pertence a outro usuário, `findForUser()` retorna `null` — o chamador
recebe `404 Not Found`, indistinguível de "lembrete não existe". Retornar
`403 Forbidden` confirmaria que o ID existe, vazando informação de enumeração.

---

## 404 vs 409: cancelamento com busca prévia

O handler de cancelamento busca o lembrete antes de verificar o status. Esta abordagem de
dois passos permite que o status HTTP correto seja retornado para cada modo de falha:

```php
// Buscar primeiro para distinguir 404 (não encontrado/proprietário errado) de 409 (status errado)
$reminder = $this->repository->findForUser($id, $userId);

if ($reminder === null) {
    return $this->responseFactory->create(['error' => 'Reminder not found.'], 404);
}

if ($reminder->status !== ReminderStatus::Pending) {
    return $this->responseFactory->create(
        ['error' => sprintf('Cannot cancel a reminder with status "%s".', $reminder->status->value)],
        409,
    );
}

$this->repository->cancel($id, $userId);
```

O cancelamento no nível do banco inclui a guarda de status como proteção final:

```php
public function cancel(int $id, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        "UPDATE reminders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$id, $userId]);

    return $stmt->rowCount() > 0;
}
```

`WHERE status = 'pending'` no UPDATE garante que uma condição de corrida (dois requests de
cancelamento concorrentes) resulte em apenas uma linha sendo atualizada.

---

## Validação de parâmetros de query (`?limit=` e `?status=`)

`limit` usa `V::queryInt()` que distingue chave ausente (usar padrão) de valor inválido
(retornar 422):

```php
$limit = V::queryInt(
    $params,
    'limit',
    ReminderRepository::MIN_LIMIT,   // 1
    ReminderRepository::MAX_LIMIT,   // 100
    ReminderRepository::DEFAULT_LIMIT, // 20 — retornado quando chave ausente
);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between %d and %d.', MIN_LIMIT, MAX_LIMIT)],
        422,
    );
}
```

`?status=` usa `V::enum()` para validar contra o enum backed:

```php
$status = V::enum($rawStatus, ReminderStatus::class);

if ($status === null) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: pending, triggered, cancelled.'],
        422,
    );
}
```

`V::enum()` chama `BackedEnum::tryFrom()` internamente, retornando `null` para valores
desconhecidos.

---

## Schema

```sql
CREATE TABLE reminders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    message    TEXT    NOT NULL,
    remind_at  TEXT    NOT NULL,  -- ISO 8601 com offset de fuso horário, armazenado como-está
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    CHECK (status IN ('pending', 'triggered', 'cancelled'))
);

CREATE INDEX idx_reminders_user   ON reminders (user_id, id);
CREATE INDEX idx_reminders_status ON reminders (status, id);
```

`remind_at` é armazenado como a string ISO 8601 original com o offset de fuso horário do remetente
(ex.: `2026-06-01T09:00:00+09:00`). O banco não normaliza para UTC — a aplicação é responsável
pela comparação correta (ver `V::futureDatetime()`).

Dois índices:
- `(user_id, id)` — cobre lista por usuário e buscas de cancelamento
- `(status, id)` — cobre uma query de polling que busca lembretes `pending` prontos para disparar

---

## Enum de status

```php
enum ReminderStatus: string
{
    case Pending   = 'pending';
    case Triggered = 'triggered';
    case Cancelled = 'cancelled';
}
```

Apenas lembretes `pending` podem ser cancelados (`409` caso contrário). `triggered` é definido por
um job em background quando o lembrete dispara — esta API não inclui o endpoint de disparo,
que seria executado em uma tarefa agendada fora do servidor HTTP.

---

## Howtos relacionados

- [`iso-datetime-validation.md`](iso-datetime-validation.md) — padrões de validação de datetime ISO 8601
- [`content-scheduling.md`](content-scheduling.md) — publicação agendada com `publish_at` futuro
- [`approval-workflow.md`](approval-workflow.md) — distinção 404/409 em transições de status
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — padrões de prevenção de IDOR
