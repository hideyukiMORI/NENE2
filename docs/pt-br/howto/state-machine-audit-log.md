# Como Fazer: Máquina de Estado com Log de Auditoria

> **Referência FT**: FT237 (`NENE2-FT/statemachinelog`) — Máquina de Estado com Log de Auditoria
> **VULN**: FT237 — avaliação de segurança/vulnerabilidade (V-01 a V-10)

Demonstra uma API de máquina de estado onde cada transição é registrada em uma tabela de log de auditoria imutável. O status atual vive no pedido; o histórico completo vive em uma tabela separada `order_transitions`. `InvalidTransitionException` fornece respostas 409 estruturadas com contexto `from` e `to`.

---

## Rotas

| Método | Caminho                         | Descrição                                    |
|--------|---------------------------------|----------------------------------------------|
| `POST` | `/orders`                       | Criar um pedido (começa como `draft`)        |
| `GET`  | `/orders/{id}`                  | Obter estado atual do pedido                 |
| `POST` | `/orders/{id}/transitions`      | Aplicar uma transição de estado              |
| `GET`  | `/orders/{id}/transitions`      | Listar histórico completo de transições      |

---

## Máquina de Estado: Transições Permitidas

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

Estados terminais (`approved`, `rejected`, `cancelled`) retornam uma lista vazia — eles não podem transicionar mais.

---

## InvalidTransitionException → 409 com Contexto

Quando um chamador solicita uma transição ilegal, a exceção carrega os estados de e para como dados estruturados para a resposta de erro:

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transição de "%s" para "%s" não é permitida.', $from->value, $to->value)
        );
    }
}
```

O controller inclui `from` e `to` na extensão Problem Details:

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

Resposta:
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transição de \"approved\" para \"submitted\" não é permitida.",
  "from": "approved",
  "to": "submitted"
}
```

`from` e `to` permitem que o chamador entenda exatamente qual transição foi rejeitada sem analisar a string `detail`.

---

## Log de Auditoria de Transição: Padrão de Duas Escritas

Toda transição bem-sucedida atualiza o status do pedido E insere um registro de log atomicamente:

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // Atualizar status atual
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // Adicionar ao log de auditoria
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **Nota de atomicidade**: Sem uma transação envolvendo, uma falha entre o UPDATE e o INSERT deixa o pedido no novo estado sem registro de log. Envolva ambas as instruções em uma transação para atomicidade real. O modo WAL do SQLite torna isso seguro sob acesso concorrente.

---

## Schema: Estado do Pedido + Histórico de Transições

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` é somente adição por design — nenhum endpoint de UPDATE ou DELETE existe para ela. O histórico completo de transições é preservado para auditoria.

---

## Resposta do Histórico de Transições

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

A lista é ordenada por `id ASC` para que o histórico seja cronológico.

---

## VULN — Avaliação de Segurança (FT237)

### V-01 — Sem autenticação em nenhum endpoint

**Ataque**: Criar pedidos e aplicar transições sem credenciais.

```bash
curl -s -X POST http://localhost:8080/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**Observado**: `200 OK` — nenhum token necessário. Qualquer pessoa pode aprovar ou cancelar qualquer pedido.

**Veredicto**: **EXPOSED** (por design para demo FT237). Adicione autenticação e autorização: proteja transições com um papel (submissor vs revisor) e restrinja cada pedido ao seu proprietário.

---

### V-02 — Valor de status inválido

**Ataque**: Enviar uma string de status desconhecida.

```json
{"status": "hacked"}
{"status": ""}
```

**Observado**: `OrderStatus::tryFrom('hacked')` = `null` → `422` com um erro listando todos os status válidos.

**Veredicto**: **BLOCKED** — backed enum `tryFrom()` rejeita valores desconhecidos.

---

### V-03 — Transição ilegal (estado terminal → ativo)

**Ataque**: Tentar transicionar de `approved` ou `cancelled` para outro status.

```json
{"status": "submitted"}   // de approved
{"status": "draft"}       // de cancelled
```

**Observado**: `canTransitionTo()` retorna `false` → `InvalidTransitionException` → `409 Conflict` com contexto `from`/`to` no corpo da resposta.

**Veredicto**: **BLOCKED** — máquina de estado aplica todas as regras de transição no nível de domínio.

---

### V-04 — ID de pedido não-numérico

**Ataque**: Passar uma string ou float como `{id}`.

```
GET /orders/abc
GET /orders/1.5
```

**Observado**: `(int) 'abc'` = 0, `(int) '1.5'` = 1. Para `abc`, `findById(0)` retorna `null` → `404 Not Found`. Para `1.5`, se o pedido 1 existe é retornado — truncamento silencioso.

**Veredicto**: **PARCIALMENTE BLOCKED** — strings não-numéricas resultam em 404. Floats são silenciosamente truncados. Adicione guarda `ctype_digit()` para validação estrita.

---

### V-05 — Histórico de transição não está escopado ao chamador

**Ataque**: Ler o histórico de transições de outro usuário.

```
GET /orders/1/transitions
```

**Observado**: `200 OK` — histórico completo retornado sem qualquer verificação de propriedade ou autenticação. O histórico revela quem submeteu, aprovou ou cancelou o pedido (via timestamps, embora nenhum ator seja registrado).

**Veredicto**: **EXPOSED** — sem modelo de propriedade. Adicione um campo `created_by` a pedidos e restrinja leituras de histórico ao proprietário ou revisores autorizados.

---

### V-06 — Injeção SQL via campo de corpo `status`

**Ataque**: Embutir metacaracteres SQL no valor `status`.

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**Observado**:
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → `422` antes de qualquer SQL.
2. Mesmo que a verificação fosse contornada, o status é passado como valor `?` parametrizado.

**Veredicto**: **BLOCKED** — camada dupla: allowlist de enum + queries parametrizadas.

---

### V-07 — Transição para o mesmo status (idempotência)

**Ataque**: Enviar uma transição para o status atual.

```json
// Pedido já está em 'submitted'
{"status": "submitted"}
```

**Observado**: `allowedTransitions()` para `submitted` é `[approved, rejected, cancelled]` — `submitted` não está na lista. `canTransitionTo(submitted)` retorna `false` → `409 Conflict`.

**Veredicto**: **BLOCKED** — auto-transições são implicitamente rejeitadas pela máquina de estado.

---

### V-08 — Transições concorrentes no mesmo pedido

**Ataque**: Enviar duas requisições de transição simultâneas para o mesmo pedido.

```
POST /orders/1/transitions {"status":"approved"}  // requisição concorrente A
POST /orders/1/transitions {"status":"rejected"}  // requisição concorrente B
```

**Observado**: Ambas as requisições buscam o pedido (status = `submitted`) antes que qualquer UPDATE seja executado. Ambas veem `canTransitionTo()` = true. Ambas fazem UPDATE — o segundo UPDATE sobrescreve o primeiro. Um registro de log de transição por requisição é inserido, mas o pedido termina em qualquer status que rodou por último. O histórico mostra ambas as transições, o que é inconsistente (ex.: `submitted → approved`, depois `submitted → rejected`).

**Veredicto**: **EXPOSED** — envolva a sequência `findById` + `canTransitionTo` + `UPDATE` + `INSERT` em uma única transação para prevenir condições de corrida.

---

### V-09 — Título apenas com espaços em branco

**Ataque**: Criar um pedido com um título em branco.

```json
{"title": "   "}
```

**Observado**: `trim($body['title'])` reduz para `""` → verificação `title === ''` dispara → `422 Unprocessable Entity`.

**Veredicto**: **BLOCKED** — `trim()` antes da verificação de string vazia trata entradas apenas com espaços.

---

### V-10 — Comprimento de título ilimitado

**Ataque**: Criar um pedido com um título muito longo.

```json
{"title": "A".repeat(100_000)}
```

**Observado**: Nenhum limite de comprimento é aplicado — títulos muito longos são armazenados na coluna `TEXT` sem restrição.

**Veredicto**: **EXPOSED** — adicione uma guarda de comprimento:
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title não deve exceder 500 caracteres.'];
}
```

---

## Resumo VULN

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| V-01 | Sem autenticação | EXPOSED |
| V-02 | Valor de status inválido | BLOCKED |
| V-03 | Transição ilegal de estado terminal | BLOCKED |
| V-04 | ID de pedido não-numérico | PARCIALMENTE BLOCKED |
| V-05 | Histórico de transição não escopado ao chamador | EXPOSED |
| V-06 | Injeção SQL via corpo status | BLOCKED |
| V-07 | Auto-transição (mesmo status) | BLOCKED |
| V-08 | Condição de corrida em transições concorrentes | EXPOSED |
| V-09 | Título apenas com espaços em branco | BLOCKED |
| V-10 | Comprimento de título ilimitado | EXPOSED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **V-01 / V-05** — Adicionar autenticação e autorização (escopo de propriedade)
2. **V-08** — Encapsular transição em uma transação
3. **V-10** — Adicionar limite de comprimento de título
4. **V-04** — Adicionar guarda `ctype_digit()` para parâmetros de ID

---

## Howtos Relacionados

- [`approval-workflow.md`](approval-workflow.md) — máquina de estado baseada em enum com endpoints de ação separados
- [`audit-trail.md`](audit-trail.md) — padrões de log de auditoria somente adição
- [`transactions.md`](transactions.md) — encapsular sequências multi-escrita em uma transação
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — prevenção de IDOR
